<?php
declare(strict_types=1);
/** Real producer SHADOW export is immutable. The positive fixture below is explicitly
 * consumer-only authorization evidence, not a supported producer promotion path. */
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
require_once __DIR__.'/fixtures/analytics/phase1_event.php';
function bridgeCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$path=getenv('ANALYTICS_ENGINE_EVENT_JSON');if(!$path)throw new RuntimeException('Synthetic producer fixture required');
$shadow=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
$pdo=analyticsTestDatabase();$pdo->exec('UPDATE analytics_task_budget SET approved=1,max_tasks=100 WHERE id=1');$admin=['id'=>1,'role'=>'admin'];$manager=['id'=>3,'role'=>'manager'];
saveManagerBinding($pdo,$admin,'Synthetic Owner',3,null);
$subject=analyticsSubjectKey($shadow['subject'],$shadow['run']['scope']);
$pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$subject,'{}','synthetic-id','VERIFIED',0,'Synthetic reviewed fixture',1]);
$client=['id'=>'synthetic-id','name'=>'Synthetic Customer','manager'=>'Synthetic Owner','manager_conflict'=>false,'status'=>'active'];
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>1],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>true,'priority'=>'NORMAL','due_days'=>3]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
$now=strtotime('2026-10-20T12:00:00Z');$day='2026-10-19';
$evidence=['upstream_confirmed'=>true,'complete_through'=>$day,'verified_at'=>'2026-10-20T09:00:00Z','valid_until'=>'2026-10-20T15:00:00Z','source_revision'=>'7'];
$policy=['approved_scope_policies'=>[$shadow['run']['scope']['policy']],'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($shadow['run']['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))],
'approved_generators'=>['inactivity/1'],'max_signal_age_seconds'=>86400,'sales'=>$evidence,'clientsvr'=>$evidence+['cache_revision'=>'synthetic-cache'],'cache_revision'=>'synthetic-cache'];
$blocked=processAnalyticsEvent($pdo,$shadow,$client,$policy,'enabled',$now);
bridgeCheck($blocked['decision']==='BLOCKED'&&in_array('SHADOW_OR_AUTOMATION_NOT_APPROVED',$blocked['reasons'],true),'Real SHADOW export must block even with approved consumer');
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Producer SHADOW cannot create task');
// Independent hypothetical authorized envelope. Never change or promote the producer fixture.
$approved=$shadow;$approved['signal_id']='consumer-only-approved';$approved['mode']='AUTOMATION_APPROVED';$approved['automation_allowed']=true;$approved['run']['automation_allowed']=true;
$approved['run']['source_decision']['coverage_status']='CONFIRMED';$approved['run']['source_decision']['business_freshness']='CURRENT';$approved['run']['source_decision']['accepted_data_through']=$day;
$approved=phase1ConsumerFixture($approved,'synthetic-id',3,'2026-10-20T15:00:00Z');
$newSubject=analyticsSubjectKey($approved['subject'],$approved['run']['scope']);
$pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$newSubject,'{}','synthetic-id','VERIFIED',0,'Synthetic phase1 fixture',1]);
$first=processAnalyticsEvent($pdo,$approved,$client,$policy,'enabled',$now);
bridgeCheck($first['decision']==='TASK_CREATED','Authorized consumer-only fixture creates task');
$task=$pdo->query('SELECT * FROM manager_tasks')->fetch(PDO::FETCH_ASSOC);
bridgeCheck($task['client_id']==='synthetic-id'&&(int)$task['assignee_id']===3&&$task['priority']==='HIGH','Stable client and correct manager/priority');
bridgeCheck($task['due_at']==='2026-10-21 12:00:00','Approved synthetic deadline');
bridgeCheck(processAnalyticsEvent($pdo,$approved,$client,$policy,'enabled',$now)['task_id']===$first['task_id'],'Replay returns same task');
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'Exactly one task after replay');
updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Synthetic contact completed']);
processAnalyticsEvent($pdo,$approved,$client,$policy,'enabled',$now);
bridgeCheck($pdo->query('SELECT status FROM manager_tasks')->fetchColumn()==='DONE','Completion retained on replay');
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_task_events')->fetchColumn()===2,'Creation and completion history retained');
// Partial delivery: first receipt committed, second fails, page checkpoint must not advance.
$pdo=analyticsTestDatabase();$second=$shadow;$second['signal_id']='synthetic-second';
$pdo->exec("CREATE TRIGGER fail_second BEFORE INSERT ON analytics_inbox WHEN NEW.signal_id='synthetic-second' BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
$transport=static function($method,$path,$body)use($shadow,$second){
 if(str_contains($path,'/publications?'))return ['items'=>[['sequence'=>1,'run_id'=>$shadow['run']['id']]],'next_after'=>null,'automation_allowed'=>false];
 return ['run'=>$shadow['run'],'items'=>[$shadow,$second],'next_after'=>null,'automation_allowed'=>false];
};
try{syncAnalytics($pdo,'dry_run',[],$transport,1);throw new LogicException('Expected second receipt failure');}catch(PDOException $e){}
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn()===1,'First receipt commits independently');
bridgeCheck((int)$pdo->query('SELECT publication_sequence FROM analytics_sync_state')->fetchColumn()===0,'Partial failure retains publication');
$pdo->exec('DROP TRIGGER fail_second');
syncAnalytics($pdo,'dry_run',[],$transport,1);
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn()===2,'Retry safely completes both receipts');
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM analytics_processing_events')->fetchColumn()===2,'Replay did not duplicate processing events');
bridgeCheck((int)$pdo->query('SELECT publication_sequence FROM analytics_sync_state')->fetchColumn()===1,'Checkpoint advances only after completion');
bridgeCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'SHADOW resume never creates tasks');
echo "analytics_producer_contract_test: real SHADOW blocked; independent approved consumer fixture one task/replay/DONE/history; partial-page failure/retry OK\n";
