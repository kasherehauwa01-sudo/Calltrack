<?php
declare(strict_types=1);
/** Allowlisted disposable SQLite/MariaDB only. Never calls getPdo() or a live Calltrack. */
$root=dirname(__DIR__);
require_once $root.'/api/analytics_permanent.php';
require_once $root.'/api/manager_tasks_service.php';
require_once $root.'/api/analytics_task_policy.php';
require_once $root.'/tests/fixtures/analytics/permanent_fixture.php';
function check(bool $v,string $m):void { if (!$v) throw new RuntimeException($m); }
function blocks(callable $f):void { try { $f(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected a blocked operation'); }
$db=churnPermanentTestDatabase();
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
 $db->exec(file_get_contents($root.'/database/20261121_analytics_permanent.sql'));
}else{
 $db->exec('CREATE TABLE analytics_permanent_policy(id INTEGER PRIMARY KEY,revision INTEGER,approved INTEGER,enabled INTEGER,daily_limit INTEGER,cooldown_days INTEGER,producer_revision INTEGER,generator_version TEXT)');
 $db->exec("INSERT INTO analytics_permanent_policy VALUES(1,0,0,0,0,0,0,'')");
 $db->exec('CREATE TABLE analytics_permanent_reservations(signal_key TEXT PRIMARY KEY,business_date TEXT,policy_revision INTEGER)');
 $db->exec('CREATE TABLE analytics_permanent_policy_events(id INTEGER PRIMARY KEY,actor TEXT,revision INTEGER,action TEXT,policy_json TEXT)');
}
$db->exec('UPDATE analytics_task_budget SET max_tasks=10,used_tasks=10');
$historical=$db->query('SELECT * FROM analytics_task_budget')->fetch(PDO::FETCH_ASSOC);

// Reproduce the exhausted production pilot in disposable fixtures, retaining real task/audit rows.
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')
 $db->exec("INSERT INTO web_users(id,display_name,role,is_active,login,pin_hash) VALUES(6,'Synthetic alternate manager','manager',1,'fixture6@example.test','synthetic-disabled-login')");
else $db->exec("INSERT INTO web_users VALUES(6,'Synthetic alternate manager','manager',1)");
foreach([3,6] as $manager)for($n=0;$n<5;$n++){
 $clientId='pilot-history-'.$manager.'-'.$n;$taskId=taskUuid();
 $signal=['signal_type'=>'CHURN_RISK','last_purchase'=>'2020-01-01'];
 $db->prepare("INSERT INTO manager_tasks(id,client_id,assignee_id,title,description,result,status,family,source_signal_key,evidence_json,created_at) VALUES(?,?,?,'Historical pilot','','','DONE','INACTIVITY',?,?,'2020-02-01 00:00:00')")
    ->execute([$taskId,$clientId,$manager,hash('sha256',$clientId),json_encode(['signal'=>$signal],JSON_THROW_ON_ERROR)]);
 taskEvent($db,$taskId,null,'CREATED',['source'=>'ANALYTICS','assignee_id'=>$manager,'priority'=>'HIGH']);
}
function pilotHistorySnapshot(PDO $db):array{
 return [
  $db->query("SELECT * FROM manager_tasks WHERE client_id LIKE 'pilot-history-%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
  $db->query("SELECT e.* FROM manager_task_events e JOIN manager_tasks t ON t.id=e.task_id WHERE t.client_id LIKE 'pilot-history-%' ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC),
  $db->query('SELECT * FROM analytics_task_budget ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
  $db->query('SELECT * FROM analytics_daily_budget ORDER BY policy_id,business_date')->fetchAll(PDO::FETCH_ASSOC),
  $db->query('SELECT * FROM analytics_daily_budget_reservations ORDER BY reservation_key')->fetchAll(PDO::FETCH_ASSOC),
 ];
}
$pilotHistory=pilotHistorySnapshot($db);
check(count($pilotHistory[0])===10&&count($pilotHistory[1])===10,'five original pilot tasks per manager retained');

$draft=['action'=>'draft','revision'=>0,'daily_limit'=>2,'cooldown_days'=>30,'producer_revision'=>2,'generator_version'=>'inactivity/aviator-churn-pilot/2'];
saveAnalyticsPermanentPolicy($db,$draft,'synthetic-operator');
blocks(fn()=>saveAnalyticsPermanentPolicy($db,['action'=>'enable','revision'=>1],'operator'));
blocks(fn()=>saveAnalyticsPermanentPolicy($db,['action'=>'approve','revision'=>1,'confirm_business_approval'=>1],'operator'));
saveAnalyticsPermanentPolicy($db,['action'=>'approve','revision'=>1,'confirm_business_approval'=>true],'operator');
check((int)$db->query('SELECT enabled FROM analytics_permanent_policy')->fetchColumn()===0,'approval does not enable');
saveAnalyticsPermanentPolicy($db,['action'=>'enable','revision'=>2],'operator');
$event=['source'=>'sales-journal','signal_id'=>'signal-1','generator_version'=>'inactivity/aviator-churn-pilot/2',
 'last_purchase'=>'2026-07-01','subject'=>['kind'=>'external_id','key'=>'synthetic-client'],
 'assignment'=>['client_id'=>'synthetic-client','manager_id'=>3],
 'signal_type'=>'CHURN_RISK','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'status'=>'ACTIVE',
 'permanent_policy'=>['version'=>'churn-permanent/1','revision'=>2,'producer_revision'=>2,'daily_limit'=>2,'cooldown_days'=>30]];
// Rollback of a failed create also rolls back its budget reservation.
$db->beginTransaction();analyticsPermanentBudget($db,$event,true);$db->rollBack();
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===0,'failed creation reservation rolled back');
$db->beginTransaction();analyticsPermanentBudget($db,$event,true);$db->commit();
$db->beginTransaction();blocks(fn()=>analyticsPermanentBudget($db,$event,true));$db->rollBack();
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===1,'duplicate reservation rejected');
$second=$event;$second['signal_id']='signal-2';$second['subject']['key']='synthetic-client-2';
$db->beginTransaction();analyticsPermanentBudget($db,$second,true);$db->commit();
$db->beginTransaction();blocks(fn()=>analyticsPermanentBudget($db,$second));$db->rollBack();
check($db->query('SELECT * FROM analytics_task_budget')->fetch(PDO::FETCH_ASSOC)===$historical,'pilot remains exhausted and unchanged');
// Isolate reservation-only checks from task-service checks in this disposable fixture.
$db->exec('DELETE FROM analytics_permanent_reservations');
saveAnalyticsTaskPolicy($db,['id'=>1,'role'=>'admin'],['revision'=>1,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[
 ['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>3],
 ['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>'NORMAL','due_days'=>3]]]);
saveAnalyticsTaskPolicy($db,['id'=>1,'role'=>'admin'],['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
$automatic=['allowed'=>true,'signal_key'=>hash('sha256','synthetic-key'),'evidence'=>['signal'=>$event]];
$input=['client_id'=>'synthetic-client','assignee_id'=>3,'family'=>'INACTIVITY','title'=>'Synthetic task','priority'=>'HIGH'];

function budgetReject(callable $f,string $reason):void{
 try{$f();}catch(AutomationBudgetBlocked|DomainException $e){check($e->getMessage()===$reason,'expected '.$reason.', got '.$e->getMessage());return;}
 throw new RuntimeException('Expected '.$reason);
}
// Both exhausted managers may create only under the independently approved permanent policy.
foreach([3,6] as $manager){
 $candidate=$automatic;$candidate['evidence']['signal']['assignment']['manager_id']=$manager;
 $db->beginTransaction();
 $made=createManagerTask($db,[],array_replace($input,['assignee_id'=>$manager]),$candidate);
 check(!$made['existing'],'permanent task created despite five historical tasks for manager '.$manager);
 check(pilotHistorySnapshot($db)===$pilotHistory,'permanent creation preserves complete pilot history');
 $db->rollBack(); // test isolation; no production data or counters are reset
 $pilot=$candidate;unset($pilot['evidence']['signal']['permanent_policy']);
 budgetReject(fn()=>createManagerTask($db,[],array_replace($input,['assignee_id'=>$manager]),$pilot),'BUDGET_EXHAUSTED');
}
// A hypothetical aggregate slot does not bypass either pilot personal cap (fixture transaction only).
$db->beginTransaction();
$db->exec('UPDATE analytics_task_budget SET used_tasks=9');
foreach([3,6] as $manager)budgetReject(fn()=>assertAnalyticsManagerPilotBudget($db,$manager),'MANAGER_PILOT_BUDGET_EXHAUSTED');
$db->rollBack();
check(pilotHistorySnapshot($db)===$pilotHistory,'pilot cap test leaves consumed counters unchanged');
foreach(['missing','unapproved','disabled','mismatch','zero','oversized'] as $failure){
 $candidate=$automatic;
 $db->beginTransaction();
 if($failure==='missing')$db->exec('DELETE FROM analytics_permanent_policy');
 if($failure==='unapproved')$db->exec('UPDATE analytics_permanent_policy SET approved=0');
 if($failure==='disabled')$db->exec('UPDATE analytics_permanent_policy SET enabled=0');
 if($failure==='mismatch')$candidate['evidence']['signal']['permanent_policy']['revision']=99;
 if(in_array($failure,['zero','oversized'],true)){
  $limit=$failure==='zero'?0:101;
  $db->exec('UPDATE analytics_permanent_policy SET daily_limit='.$limit);
  $candidate['evidence']['signal']['permanent_policy']['daily_limit']=$limit;
 }
 budgetReject(fn()=>createManagerTask($db,[],$input,$candidate),'PERMANENT_POLICY_NOT_APPROVED');
 check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===0,'invalid policy reserves nothing');
 $db->rollBack();
}
$outside=$automatic;$outside['evidence']['signal']['assignment']['manager_id']=4;
budgetReject(fn()=>createManagerTask($db,[],array_replace($input,['assignee_id'=>4]),$outside),'MANAGER_OUTSIDE_PILOT');
check(pilotHistorySnapshot($db)===$pilotHistory,'failed pilot/permanent creates preserve historical rows and counters');

$first=createManagerTask($db,[],$input,$automatic);$replay=createManagerTask($db,[],$input,$automatic);
check(!$first['existing']&&$replay['existing']&&$first['task']['id']===$replay['task']['id'],'atomic service replay');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===1,'replay consumes no new budget');
updateManagerTask($db,['id'=>3,'role'=>'manager'],$first['task']['id'],['version'=>1,'status'=>'DONE','result'=>'Synthetic completion']);
$same=$automatic;$same['signal_key']=hash('sha256','new-signal-same-anchor');$same['evidence']['signal']['signal_id']='new-id';
$closed=createManagerTask($db,[],$input,$same);
check($closed['existing']&&$closed['task']['status']==='DONE','closed episode is not reopened');
$new=$automatic;$new['signal_key']=hash('sha256','new-purchase');$new['evidence']['signal']['signal_id']='new-purchase';$new['evidence']['signal']['last_purchase']='2026-08-01';
blocks(fn()=>createManagerTask($db,[],$input,$new));
check((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===11,'cooldown blocks new purchase until approved interval');
$db->exec("UPDATE manager_tasks SET created_at='2020-01-01 00:00:00' WHERE client_id='synthetic-client'");
$after=createManagerTask($db,[],$input,$new);
check(!$after['existing'],'new purchase after cooldown creates new episode');
check($db->query('SELECT * FROM analytics_task_budget')->fetch(PDO::FETCH_ASSOC)===$historical,'new mode never consumes or refunds pilot budget');
// The permanent daily ceiling still blocks new work; no lifetime-cap fallback.
$third=$automatic;$third['signal_key']=hash('sha256','daily-overflow');
$third['evidence']['signal']['signal_id']='daily-overflow';
$third['evidence']['signal']['subject']['key']=$third['evidence']['signal']['assignment']['client_id']='daily-overflow';
budgetReject(fn()=>createManagerTask($db,[],array_replace($input,['client_id'=>'daily-overflow']),$third),'PERMANENT_DAILY_LIMIT');
check(pilotHistorySnapshot($db)===$pilotHistory,'all ten original tasks, creation events and pilot counters unchanged');
saveAnalyticsPermanentPolicy($db,['action'=>'disable','revision'=>2],'operator');
$db->beginTransaction();blocks(fn()=>analyticsPermanentPolicy($db,$event));$db->rollBack();
echo "permanent_budget_test: exhausted 5+5/10 pilot, independent approved policy, fail-closed bounds, rollback, daily cap, full history preservation, replay, closed episode, cooldown OK\n";
