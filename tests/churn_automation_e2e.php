<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$dsn=getenv('ANALYTICS_TASK_TEST_DSN');
if($dsn!=='mysql:host=analytics-test-mariadb;dbname=analytics_sync_test')throw new RuntimeException('Dedicated MariaDB fixture required');
function fixturePdo():PDO{return new PDO(getenv('ANALYTICS_TASK_TEST_DSN'),getenv('ANALYTICS_TASK_TEST_USER'),getenv('ANALYTICS_TASK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
if(($argv[1]??'')==='worker'){
 $data=json_decode(file_get_contents('/tmp/churn-events.json'),true,64,JSON_THROW_ON_ERROR);$index=(int)$argv[2];$pdo=fixturePdo();
 for($n=0;$n<5;$n++)try{
  $r=processAnalyticsEvent($pdo,$data['events'][$index],$data['clients'][$index],$data['policy'],'enabled',time());echo $r['decision'].PHP_EOL;exit(0);
 }catch(PDOException $e){if(!in_array($e->getCode(),['40001','23000'],true))throw $e;usleep(20000);}
 exit(1);
}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];
$pub=salesJournalRequest('GET',analyticsFeedPath('/api/integrations/calltrack/analytics/publications?page_size=1'));
$rid=$pub['items'][0]['run_id']??'';verify($rid!=='','Native publication over HTTP');
$path=analyticsFeedPath('/api/integrations/calltrack/analytics/runs/'.$rid.'/changes');
$page=salesJournalRequest('GET',$path);$events=$page['items'];verify(count($events)===2,'Only two CHURN candidates');
verify($page===salesJournalRequest('GET',$path),'Immutable HTTP replay');
$now=time();$day=$events[0]['as_of'];
$evidence=['upstream_confirmed'=>true,'complete_through'=>$day,'verified_at'=>gmdate('c',$now-60),'valid_until'=>gmdate('c',$now+1800),'source_revision'=>(string)$events[0]['run']['source_revision']];
saveManagerBinding($pdo,$admin,'Synthetic Owner A',3,null);saveManagerBinding($pdo,$admin,'Synthetic Owner B',4,null);
clientsEnsureDirectory(dirname(clientsSqliteFile()));$directory=new PDO('sqlite:'.clientsSqliteFile());
$directory->exec('CREATE TABLE clients(client_id TEXT PRIMARY KEY,name TEXT,payload_json TEXT)');$clients=[];
foreach($events as $i=>$event){
 verify($event['mode']==='AUTOMATION_APPROVED'&&$event['automation_allowed']===true&&$event['signal_type']==='CHURN_RISK','Native authorized CHURN');
 $id=$event['subject']['key'];$owner=$i===0?'Synthetic Owner A':'Synthetic Owner B';
 $clients[]=['id'=>$id,'name'=>'Synthetic fixture','manager'=>$owner,'manager_conflict'=>false,'status'=>'active'];
 $row=['id'=>$id,'name'=>'Synthetic fixture','fields'=>['Менеджер'=>$owner,'status'=>'active']];
 $directory->prepare('INSERT INTO clients VALUES(?,?,?)')->execute([$id,'Synthetic fixture',json_encode($row)]);
 $pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([analyticsSubjectKey($event['subject'],$event['run']['scope']),'{}',$id,'VERIFIED',0,'Synthetic reviewed stable ID',1]);
}
$directory=null;$cache=clientCatalogRevision();
$policy=['approved_scope_policies'=>[$events[0]['run']['scope']['policy']],
 'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($events[0]['run']['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))],
 'approved_generators'=>['inactivity/1'],'max_signal_age_seconds'=>3600,'sales'=>$evidence,'clientsvr'=>$evidence+['cache_revision'=>$cache],'cache_revision'=>$cache];
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>1],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
$disabled=processAnalyticsEvent($pdo,$events[0],$clients[0],$policy,'disabled',$now);
verify($disabled['decision']==='DISABLED','Disabled does not process');
$unapproved=processAnalyticsEvent($pdo,$events[0],$clients[0],$policy,'enabled',$now);
verify(in_array('TASK_POLICY_NOT_APPROVED',$unapproved['reasons'],true),'Actual MariaDB unapproved policy blocks');
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
$r=processAnalyticsEvent($pdo,$events[0],$clients[0],$policy,'enabled',$now);verify(in_array('BUDGET_NOT_APPROVED',$r['reasons'],true),'Unset budget blocks');
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>1]);
foreach(['unknown client','unknown manager','stale sales','unknown coverage','disabled type'] as $case){
 $client=$clients[0];$config=$policy;$event=$events[0];
 if($case==='unknown client')$client=null;
 elseif($case==='unknown manager')$client['manager']='No verified mapping';
 elseif($case==='stale sales')$config['sales']['valid_until']=gmdate('c',$now-1);
 elseif($case==='unknown coverage')$event['run']['source_decision']['coverage_status']='UNKNOWN';
 else $pdo->exec("UPDATE analytics_task_type_policy SET enabled=0 WHERE signal_type='CHURN_RISK'");
 // Avoid conflicting immutable receipt content for the altered UNKNOWN envelope.
 if($case==='unknown coverage')$event['signal_id']='unknown-coverage-test';
 $refused=processAnalyticsEvent($pdo,$event,$client,$config,'enabled',$now);
 verify($refused['decision']==='BLOCKED','Actual MariaDB refusal: '.$case);
 verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'No task: '.$case);
 verify((int)analyticsBudget($pdo)['used_tasks']===0,'No spend: '.$case);
 if($case==='disabled type')$pdo->exec("UPDATE analytics_task_type_policy SET enabled=1 WHERE signal_type='CHURN_RISK'");
}
file_put_contents('/tmp/churn-events.json',json_encode(['events'=>$events,'clients'=>$clients,'policy'=>$policy],JSON_THROW_ON_ERROR));
$processes=[];foreach([0,0,1] as $index){$pipes=[];$processes[]=proc_open([PHP_BINARY,__FILE__,'worker',(string)$index],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
foreach($processes as $process)verify(proc_close($process)===0,'Concurrent workers');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'Concurrent hard cap');
verify((int)$pdo->query('SELECT used_tasks FROM analytics_task_budget')->fetchColumn()===1,'Replay does not consume budget');
$task=$pdo->query('SELECT * FROM manager_tasks')->fetch(PDO::FETCH_ASSOC);$winner=$task['client_id']===$clients[0]['id']?0:1;$other=1-$winner;
verify((int)$task['assignee_id']===$events[$winner]['assignment']['manager_id'],'Correct stable manager');
$r=processAnalyticsEvent($pdo,$events[$other],$clients[$other],$policy,'enabled',$now);verify(in_array('BUDGET_EXHAUSTED',$r['reasons'],true),'Exhausted receipt persists');
$fail=true;$transport=static function($method,$path,$body)use(&$fail){if($fail&&str_contains($path,'/runs/')){$fail=false;throw new RuntimeException('Synthetic network interruption');}return salesJournalRequest($method,$path,$body);};
try{syncAnalytics($pdo,'enabled',$policy,$transport,1);throw new LogicException('Expected interruption');}catch(RuntimeException $e){if($e->getMessage()!=='Synthetic network interruption')throw $e;}
verify((int)$pdo->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()===0,'Network checkpoint retained');
$quoted=$pdo->quote($events[1]['signal_id']);
$pdo->exec("CREATE TRIGGER fail_second_receipt BEFORE UPDATE ON analytics_inbox FOR EACH ROW BEGIN IF NEW.signal_id=$quoted THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic partial failure'; END IF; END");
try{syncAnalytics($pdo,'enabled',$policy,null,1);throw new LogicException('Expected partial receipt failure');}catch(PDOException $e){if($e->getCode()!=='45000')throw $e;}
verify((int)$pdo->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()===0,'Partial MariaDB failure retains page cursor');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'First committed effect preserved');
$pdo->exec('DROP TRIGGER fail_second_receipt');
syncAnalytics($pdo,'enabled',$policy,null,1);
verify((int)$pdo->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()===1,'Real HTTP retry');
verify((int)$pdo->query('SELECT publication_sequence FROM analytics_sync_state')->fetchColumn()===0,'SHADOW cursor untouched');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'HTTP replay no duplicate');
updateManagerTask($pdo,['id'=>(int)$task['assignee_id'],'role'=>'manager'],$task['id'],['version'=>1,'status'=>'DONE','result'=>'Synthetic contact completed']);
processAnalyticsEvent($pdo,$events[$winner],$clients[$winner],$policy,'enabled',$now);
verify($pdo->query('SELECT status FROM manager_tasks')->fetchColumn()==='DONE','Completion retained');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_task_events')->fetchColumn()===2,'History persisted');
$unsupported=$events[0];$unsupported['signal_id']='unsupported-test';$unsupported['signal_type']='PURCHASE_OVERDUE';
$binding=analyticsBinding($pdo,analyticsSubjectKey($unsupported['subject'],$unsupported['run']['scope']));
$gate=analyticsGate($unsupported,$binding,$clients[0],managerBindingRows($pdo),array_replace($policy,analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo))),$now);
verify(in_array('PHASE1_CHURN_ONLY',$gate['reasons'],true),'Other type blocked');
$latest=salesJournalRequest('GET','/api/integrations/calltrack/analytics/latest');verify($latest['mode']==='SHADOW'&&$latest['automation_allowed']===false,'SHADOW history immutable');
$source=salesJournalRequest('GET','/api/integrations/calltrack/analytics/runs/'.$latest['run']['id'].'/changes');
$r=processAnalyticsEvent($pdo,$source['items'][0],null,$policy,'enabled',$now);verify(in_array('SHADOW_OR_AUTOMATION_NOT_APPROVED',$r['reasons'],true),'Real SHADOW blocked');
$pdo->exec('DELETE FROM analytics_task_budget');$new=$events[$other];$new['signal_id']='budget-unavailable';
$r=processAnalyticsEvent($pdo,$new,$clients[$other],$policy,'enabled',$now);verify(in_array('BUDGET_UNAVAILABLE',$r['reasons'],true),'Budget failure closed');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'No task on budget failure');
echo "REAL E2E PASS: PostgreSQL worker/release + HTTP + MariaDB; cap1 concurrency/replay, correct manager, DONE/history, other types/SHADOW/budget failures blocked\n";
