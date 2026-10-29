const {chromium}=require('playwright');
const path=require('node:path');const root=process.argv[2] || path.resolve(__dirname,'..');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
 for(const role of ['manager','admin','supervisor']){
  const page=await browser.newPage({viewport:role==='manager'?{width:390,height:844}:{width:1440,height:1000}});await page.setContent('<span id="tasksCount" hidden></span><div id="tasksView"></div>');
  await page.evaluate(role=>{
   window.role=role;window.calls=[];window.saved=false;window.openClientHistoryModal=(name,reference)=>{window.openedClient=reference.client_id;};
   window.calltrackApi={loadClientEvents:()=>new Promise((resolve,reject)=>{window.finishClientLoad=()=>window.failClientLoad ? reject(new Error('Ошибка сервера')) : resolve({calls:[],emails:[]});}),requestJson:async(url,opts)=>{
    window.calls.push([url,opts]);
    if(opts.method==='PATCH'){window.saved=true;return {};}
    if(url.includes('count_only'))return {unfinished_count:7};
    if(url.includes('?id='))return {role,client:{name:'ООО Радуга'},history:[{event_type:'CREATED'}],task:{id:'1',created_at:'2026-10-29 10:00:00',client_id:'c',title:'Риск ухода клиента',assignee_id:1,assignee_name:'Менеджер',status:'DONE',priority:'NORMAL',version:1,result:'Готово',source_signal_key:'s',description:'Частота покупок: каждые 20 дней.',evidence_json:JSON.stringify({signal:{matching:{status:'PRELIMINARY_MATCH',member_ids:[]}}})}};
    return {role,items:[{id:'1',created_at:'2026-10-29 10:00:00',client_id:'c',client_name:'ООО Радуга',title:'Риск ухода клиента',status:'OPEN',priority:'NORMAL'}],assignees:role==='manager'?[]:[{id:1,display_name:'Менеджер'}],types:[{key:'CHURN_RISK',label:'Риск ухода клиента'},{key:'NEW',label:'Новый тип'}],unfinished_count:7,next_after:null};
   }};
  },role);
  await page.addStyleTag({content:'body{font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:16px}button,select,input,textarea{font:inherit}'});
  await page.addStyleTag({path:path.join(root,'analizmop/manager-tasks.css')});
  await page.addScriptTag({path:path.join(root,'analizmop/manager-tasks.js')});
  await page.evaluate(()=>window.refreshManagerTasksCount());assert.equal(await page.locator('#tasksCount').textContent(),'7');
  await page.evaluate(role=>window.openManagerTasks({id:1,role}),role);
  await page.locator('[data-task="1"]').waitFor();
  const cardText=await page.locator('[data-task="1"]').textContent();
  for(const label of ['Клиент ID','Приоритет','Срок (UTC)'])assert.equal(cardText.includes(label),false);
  assert.equal(await page.locator('.task-status--open').textContent(),'Открыта');assert.equal(await page.locator('#taskManagerFilter').count(),role==='manager'?0:1);
  assert.match(await page.locator('[data-task="1"]').textContent(),/ООО Радуга/);assert.match(await page.locator('[data-task="1"]').textContent(),/Добавлена: 29.10.2026/);
  await page.locator('#tasksCompleted').click();await page.waitForFunction(()=>window.calls.at(-1)[0].includes('completed=1'));
  await page.locator('[data-task-type="NEW"]').click();await page.waitForFunction(()=>window.calls.at(-1)[0].includes('type=NEW'));
  await page.locator('[data-task="1"]').click();await page.locator('#taskEdit').waitFor();
  assert.equal(await page.locator('.task-detail').count(),1);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No horizontal overflow');
  if(process.env.TASK_PREVIEW_DIR)await page.screenshot({path:process.env.TASK_PREVIEW_DIR+'/task-detail-'+role+'.png',fullPage:true});
  await page.locator('#taskClientCard').click();
  assert.equal(await page.locator('#taskClientCard').isDisabled(),true);
  assert.match(await page.locator('#taskClientLoading').textContent(),/Загрузка карточки/);
  assert.equal(await page.locator('.task-spinner').count(),1);
  await page.evaluate(()=>window.finishClientLoad());await page.waitForFunction(()=>window.openedClient==='c');
  assert.equal(await page.locator('#taskClientCard').isEnabled(),true);
  assert.equal(await page.locator('#taskClientLoading').textContent(),'');
  await page.evaluate(()=>window.failClientLoad=true);await page.locator('#taskClientCard').click();await page.evaluate(()=>window.finishClientLoad());
  await page.waitForFunction(()=>document.querySelector('#taskClientLoading').textContent.includes('Попробуйте ещё раз'));
  assert.equal(await page.locator('#taskClientCard').isEnabled(),true);
  assert.equal(await page.locator('.task-spinner').count(),0);
  const body=await page.locator('#tasksView').textContent();
  for(const word of ['Предварительное сопоставление','Исходный аналитический сигнал','Приоритет','История'])assert.equal(body.includes(word),role!=='manager',word+' '+role);
  if(role!=='supervisor'){
   await page.locator('[name=result]').fill('Исправленный результат');await page.locator('[type=submit]').click();await page.locator('#tasksCompleted').waitFor();assert.equal(await page.evaluate(()=>window.saved),true);
  }else assert.equal(await page.locator('#taskEdit [type=submit]').count(),0);
  await page.close();
 }
 console.log('PASS: browser roles, count, type/completed filters, client name, DONE editing, save returns to list');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
