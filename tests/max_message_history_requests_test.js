const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const html=fs.readFileSync(__dirname+'/../analizmop/index.html','utf8');
function fn(name){const start=html.indexOf('function '+name+'('),end=html.indexOf('\nfunction ',start+1);assert(start>=0);return html.slice(start,end);}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function setup(groups,api){
 const requests=[];
 const ctx={Map,Set,Promise,JSON,authenticatedWebUser:{id:1},window:{calltrackApi:{loadClientMaxHistory:ref=>{requests.push(ref);return api(ref);}}},els:{clientCallsList:{dataset:{},innerHTML:''}},document:{getElementById:()=>({textContent:''})},groupCallsByClient:()=>groups,allCalls:[],summarizeClientHistory:()=>({}),clientSalesAvailable:()=>false,renderedSaleGroups:new Map(),getClientPeriodRange:()=>({from:'2026-10-01',to:'2026-10-19'}),formatDateLocal:x=>x,maxIdentityForGroup:g=>g.identity,renderClientRow:(g,items)=>'CALL EMAIL SALE '+items.length,escapeHtml:x=>x};
 vm.createContext(ctx);vm.runInContext('const maxHistoryCache=new Map();const maxHistoryQueue=[];let maxHistoryInFlight=0;',ctx);
 for(const name of ['pumpMaxHistory','loadMaxHistory','maxReferenceForGroup','renderClientCalls'])vm.runInContext(fn(name),ctx);
 return {ctx,requests};
}
const group=(identity,id,email=false)=>({identity,calls:email?[]:[{sqlId:id}],emails:email?[{id}]:[]});
(async()=>{
 const row={date:'2026-10-19',incoming_count:0,outgoing_count:1,last_event_at:'2026-10-18T21:30:00Z'};
 for(const data of [[row],[]]){
  const {ctx,requests}=setup([1,2,3].map(id=>group('client:a',id)),async()=>data);
  ctx.renderClientCalls();await tick();await tick();ctx.renderClientCalls();assert.equal(requests.length,1);
  console.log('3 call references, '+(data.length?'data':'empty')+': 1 request');
 }
 const mixed=setup([group('client:a',1),group('client:a',2,true),group('client:a',3)],async()=>[]);
 mixed.ctx.renderClientCalls();await tick();assert.equal(mixed.requests.length,1);
 const two=setup([group('client:a',1),group('client:b',2)],async()=>[]);
 two.ctx.renderClientCalls();await tick();assert.equal(two.requests.length,2);
 let release;const parallel=setup([],()=>new Promise(resolve=>release=resolve));
 const a=parallel.ctx.loadMaxHistory('client:a',{call_id:1}),b=parallel.ctx.loadMaxHistory('client:a',{email_id:2});
 assert.equal(a,b);assert.equal(a.promise,b.promise);await tick();assert.equal(parallel.requests.length,1);release([]);await a.promise;
 for(const error of ['401','403','500','network','timeout']){
  const failed=setup([1,2,3].map(id=>group('client:a',id)),async()=>{throw new Error(error);});
  failed.ctx.renderClientCalls();await tick();await tick();failed.ctx.renderClientCalls();
  assert.equal(failed.requests.length,1);assert(failed.ctx.els.clientCallsList.innerHTML.includes('CALL EMAIL SALE'));assert(failed.ctx.els.clientCallsList.innerHTML.includes('История MAX временно недоступна'));
  vm.runInContext('maxHistoryCache.clear()',failed.ctx);failed.ctx.renderClientCalls();await tick();assert.equal(failed.requests.length,2);
 }
 assert(html.includes('function applyCallsPayload(callsPayload){\n  maxHistoryCache.clear();'));
 console.log('A-F, in-flight sharing, error isolation and explicit reload invalidation: OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
