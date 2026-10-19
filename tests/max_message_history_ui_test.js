const assert=require('node:assert/strict');
const fs=require('node:fs');const vm=require('node:vm');const child=require('node:child_process');
const html=fs.readFileSync(require('node:path').join(__dirname,'../analizmop/index.html'),'utf8');
for(const script of html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g))new vm.Script(script[1]);
function fn(name){const start=html.indexOf('function '+name+'(');assert(start>=0,name);const next=html.indexOf('\nfunction ',start+1);return html.slice(start,next<0?undefined:next);}
const ctx={Intl,Date,Map,Set,Promise,JSON,console,createLocalDate:(y,m,d,h=0,min=0,sec=0)=>new Date(y,m,d,h,min,sec),
 escapeHtml:v=>String(v).replace(/[<>&"]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])),formatDateDisplay:v=>v.split('-').reverse().join('.'),
 formatDateLocal:d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`,
 formatCallDurationDot:()=> '1.00',formatCallDuration:()=> '1:00',callHasHistoryDetails:()=>true,formatRubles:v=>v+' руб.',
 clientSalesAvailable:()=>true,renderedSaleGroups:new Map(),groupSalesByDate:items=>items.map(s=>({date:s.sale_date,sales:[s],totalAmount:s.total_amount})),parseDurationSeconds:()=>60,
 clientRecordsByPhone:new Map(),clientRecordsByEmail:new Map(),normalizeClientLookupPhone:v=>String(v).replace(/\D/g,'').slice(-10),normalizeClientLookupEmail:v=>v};
vm.createContext(ctx);
for(const name of ['maxBusinessTime','maxEventTime','renderMaxHistoryCard','maxReferenceForGroup','maxIdentityForGroup','sortClientTimelineEvents','renderClientEmailCard','renderClientRow'])vm.runInContext(fn(name),ctx);
const max={date:'2026-10-19',incoming_count:6,outgoing_count:4,last_event_at:'2026-10-18T21:30:00Z'};
const call={__dt:new Date(2026,9,19,0,20),__type:'Исходящий',__index:0,duration:60,manager:'One',phone:'9991111111'};
const group={client:'Client',displayName:'Client',calls:[call],emails:[{id:3,__dt:new Date(2026,9,19,0,40),subject:'Existing email'}],sales:[{sale_id:4,sale_date:'2026-10-19',total_amount:20}]};
let out=ctx.renderClientRow(group,[max]);
assert.equal((out.match(/Переписка MAX/g)||[]).length,1);
assert(out.includes('Входящие: 6')&&out.includes('Исходящие: 4'));
assert(out.includes('19.10.2026')&&out.includes('00:30'));
assert(out.indexOf('Исходящий')<out.indexOf('Переписка MAX'));
assert(out.indexOf('Переписка MAX')<out.indexOf('Existing email'));
assert(out.indexOf('Existing email')<out.indexOf('Продаж - 1'));
for(const [type,direction] of [['Входящий','incoming'],['Исходящий','outgoing']]){
 const maxCall=ctx.renderClientRow({...group,calls:[{...call,source:'max',__type:type}]});
 assert(maxCall.includes(`call-segment ${direction} max-call`));
 assert(maxCall.includes(`MAX · ${type}`));
 const regularCall=ctx.renderClientRow({...group,calls:[{...call,source:'phone',__type:type}]});
 assert(!regularCall.includes('max-call'));
}
for(const [incoming,outgoing] of [[3,0],[0,5]]){
 const card=ctx.renderMaxHistoryCard({...max,incoming_count:incoming,outgoing_count:outgoing},true);
 assert(card.includes(`Входящие: ${incoming}`)&&card.includes(`Исходящие: ${outgoing}`));assert(card.includes('history-item'));
}
for(const modal of [false,true]){
 const card=ctx.renderMaxHistoryCard({...max,last_event_at:'2026-10-18T17:30:00Z'},modal);
 assert(card.includes('18.10.2026 · 20:30')); // Date comes from last_event_at, not item.date/browser zone.
 assert(card.includes('max-message'));
}
for(const field of ['source_event_id','max_user_id','shortcut_id','contact_name','message_text'])assert(!out.includes(field));
ctx.clientRecordsByPhone.set('9991111111',[{id:'a'}]);
assert.equal(ctx.maxIdentityForGroup({calls:[{phone:'9991111111',userPhone:'web-user-1'}]}),'client:a');
ctx.clientRecordsByPhone.set('9992222222',[{id:'a'}]);
assert.equal(ctx.maxIdentityForGroup({calls:[{phone:'9992222222',userPhone:'web-user-1'}]}),'client:a');
ctx.clientRecordsByPhone.set('9993333333',[{id:'a'},{id:'b'}]);
assert.equal(ctx.maxIdentityForGroup({calls:[{phone:'9993333333',userPhone:'web-user-1'}]}),null);
assert.equal(ctx.maxIdentityForGroup({calls:[{phone:'9991111111',userPhone:'web-user-1'},{phone:'9992222222',userPhone:'web-user-2'}]}),null);
// The card date/time and relative wall-clock ordering must be independent of browser timezone.
if(!process.env.MAX_HISTORY_CHILD){for(const tz of ['UTC','Europe/Volgograd','America/Los_Angeles'])child.execFileSync(process.execPath,[__filename],{env:{...process.env,TZ:tz,MAX_HISTORY_CHILD:'1'}});}
assert(html.includes('const maxEntry=loadMaxHistory(reference.sales_client_key?'))
assert(html.includes('sales-buyer:${reference.sales_client_key}:manager:'));
assert(html.includes('maxItems.forEach'));
assert(!fs.readFileSync(require('node:path').join(__dirname,'../analizmop/api.js'),'utf8').includes('resolvedOptions().timeZone'));
console.log('max_message_history_ui_test: OK '+(process.env.TZ||'default'));
