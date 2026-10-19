const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const html=fs.readFileSync(__dirname+'/../analizmop/index.html','utf8');
function fn(name){const start=html.indexOf('function '+name+'(');assert(start>=0,name);const end=html.indexOf('\nfunction ',start+1),nextConst=html.indexOf('\nconst ',start+1);return html.slice(start,nextConst>=0&&nextConst<end?nextConst:end);}
const ctx={Map,Set,Date,console,authenticatedWebUser:{role:'admin'},availableManagers:['Пашута Марина Сергеевна','Селянкина Татьяна'],activeClientManager:'Пашута Марина Сергеевна',clientOutgoingEmails:[],
 els:{clientManagerTags:{innerHTML:''}},getClientPeriodRange:()=>({from:new Date(2026,9,1),to:new Date(2026,9,31,23,59,59)}),escapeHtml:String,
 normalizeCallTypeLabel:v=>v,parseDurationSeconds:Number,getCallDateTime:c=>new Date(c.date+'T'+c.time),inDateRange:(d,a,b)=>d>=a&&d<=b,
 getClientKey:()=> 'Client',getClientDisplayName:()=> 'Client',callClientIntegrationKey:()=> 'client:a',emailClientIntegrationKey:()=> 'client:a',canonicalSalesClientKey:x=>x,
 normalizeDateTime:s=>new Date(s.replace(' ','T')),parseLocalDate:s=>new Date(s+'T00:00:00'),normalizeClientLookupEmail:s=>s,clientNamesByEmail:new Map(),currentClientSales:()=>[],normalizeClientLookupPhone:String};
vm.createContext(ctx);for(const name of ['clientManagerNameKey','populateClientManagers','groupCallsByClient'])vm.runInContext(fn(name),ctx);
const calls=[{manager:'Сергеевна Пашута Марина',date:'2026-10-18',time:'12:00:00',call_type:'Исходящий',type:'Исходящий',duration:60,phone:'9992223344'}];
const mail=(id,manager,sent_at='2026-10-18 12:30:00')=>({id,manager_name:manager,client_email:'buyer@example.test',sent_at});
ctx.clientOutgoingEmails=[mail(1,'Пашута Марина Сергеевна'),mail(2,'  марина   сергеевна пашута  '),mail(3,'Селянкина Татьяна'),mail(4,'Пашута Марина Сергеевна','2026-09-30 10:00:00')];
// Reproduce the former loss when the selected tag used the historical call spelling.
ctx.activeClientManager='Сергеевна Пашута Марина';
vm.runInContext(fn('groupCallsByClient').replaceAll('clientManagerNameKey(manager)!==clientManagerNameKey(activeClientManager)','manager!==activeClientManager'),ctx);
assert.equal(ctx.groupCallsByClient(calls)[0].emails.length,0);
vm.runInContext(fn('groupCallsByClient'),ctx);
ctx.populateClientManagers(calls);
assert.equal(ctx.activeClientManager,'Пашута Марина Сергеевна');
assert.equal((ctx.els.clientManagerTags.innerHTML.match(/data-manager=/g)||[]).length,2,'same manager must not split into multiple tags');
let groups=ctx.groupCallsByClient(calls);
assert.equal(groups.length,1);assert.equal(groups[0].calls.length,1);assert.equal(groups[0].emails.length,2);
assert.deepEqual(Array.from(groups[0].emails,e=>e.id),[1,2]);
ctx.activeClientManager='Татьяна Селянкина';groups=ctx.groupCallsByClient(calls);
assert.equal(groups.length,1);assert.equal(groups[0].calls.length,0);assert.equal(groups[0].emails[0].id,3);
ctx.authenticatedWebUser={role:'manager',display_name:'Пашута Марина Сергеевна'};ctx.populateClientManagers(calls);
assert.equal((ctx.els.clientManagerTags.innerHTML.match(/data-manager=/g)||[]).length,1);
assert.equal(ctx.groupCallsByClient([])[0].emails.length,2,'email-only history must render');
console.log('client_email_timeline: canonical manager names, two managers, email-only history, period and manager filters OK');
