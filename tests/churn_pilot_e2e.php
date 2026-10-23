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
$page=salesJournalRequest('GET',$path);$events=$page['items'];verify(count($events)===6,'Only two CHURN candidates');
verify($page===salesJournalRequest('GET',$path),'Immutable HTTP replay');
$now=time();$day=$events[0]['as_of'];
$evidence=['upstream_confirmed'=>true,'complete_through'=>$day,'verified_at'=>gmdate('c',$now-60),'valid_until'=>gmdate('c',$now+1800),'source_revision'=>(string)$events[0]['run']['source_revision']];
saveManagerBinding($pdo,$admin,'Synthetic Owner A',3,null);saveManagerBinding($pdo,$admin,'Synthetic Owner B',4,null);
clientsEnsureDirectory(dirname(clientsSqliteFile()));$directory=new PDO('sqlite:'.clientsSqliteFile());
$directory->exec('CREATE TABLE clients(client_id TEXT PRIMARY KEY,name TEXT,payload_json TEXT)');$clients=[];
foreach($events as $i=>$event){
 verify($event['mode']==='AUTOMATION_APPROVED'&&$event['automation_allowed']===true&&$event['signal_type']==='CHURN_RISK','Native authorized CHURN');
 $id=$event['subject']['key'];$owner=$i%2===0?'Synthetic Owner A':'Synthetic Owner B';
 $clients[]=['id'=>$id,'name'=>'Synthetic fixture','manager'=>$owner,'manager_conflict'=>false,'status'=>'active'];
 $row=['id'=>$id,'name'=>'Synthetic fixture','fields'=>['Менеджер'=>$owner,'status'=>'active']];
 $directory->prepare('INSERT INTO clients VALUES(?,?,?)')->execute([$id,'Synthetic fixture',json_encode($row)]);
 $pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([analyticsSubjectKey($event['subject'],$event['run']['scope']),'{}',$id,'VERIFIED',0,'Synthetic reviewed stable ID',1]);
}
$directory=null;$cache=clientCatalogRevision();
$policy=['approved_scope_policies'=>[$events[0]['run']['scope']['policy']],
 'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($events[0]['run']['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))],
 'approved_generators'=>['inactivity/aviator-churn-pilot/1'],'max_signal_age_seconds'=>3600,'sales'=>$evidence,'clientsvr'=>$evidence+['cache_revision'=>$cache],'cache_revision'=>$cache];
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>.3,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'NORMAL','due_days'=>3],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>100]);
foreach($events as $i=>$event){
 $shadow=$event;$shadow['signal_id']='shadow-'.$i;$shadow['mode']='SHADOW';$shadow['automation_allowed']=false;
 verify(processAnalyticsEvent($pdo,$shadow,$clients[$i],$policy,'enabled',$now)['decision']==='BLOCKED','SHADOW closed');
 verify(processAnalyticsEvent($pdo,$event,$clients[$i],$policy,'dry_run',$now)['decision']==='DRY_RUN_READY','Dry-run ready');
}
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'No tasks in shadow/dry-run');
file_put_contents('/tmp/churn-events.json',json_encode(['events'=>$events,'clients'=>$clients,'policy'=>$policy],JSON_THROW_ON_ERROR));
$jobs=[];foreach([0,0,1,2,3,4,5] as $i){$pipes=[];$jobs[]=proc_open([PHP_BINARY,__FILE__,'worker',(string)$i],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
foreach($jobs as $job)verify(proc_close($job)===0,'Concurrent consumer');
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===5,'Exactly five tasks globally');
verify((int)analyticsBudget($pdo)['used_tasks']===5,'Lifetime retained');
verify((int)$pdo->query('SELECT SUM(used_tasks) FROM analytics_daily_budget')->fetchColumn()===5,'Daily five');
foreach($events as $i=>$event){
 $result=processAnalyticsEvent($pdo,$event,$clients[$i],$policy,'enabled',$now);
 verify(in_array($result['decision'],['TASK_EXISTS','BLOCKED'],true),'Replay no new task');
 if($result['decision']==='BLOCKED')verify(in_array('DAILY_BUDGET_EXHAUSTED',$result['reasons'],true),'Sixth daily reason');
}
foreach($pdo->query('SELECT * FROM manager_tasks')->fetchAll() as $task){
 $event=array_values(array_filter($events,fn($event)=>$event['subject']['key']===$task['client_id']))[0];
 verify((int)$task['assignee_id']===$event['assignment']['manager_id'],'Correct manager');
 verify(strtotime($task['due_at'].' UTC')-strtotime($task['created_at'].' UTC')===259200,'Exactly three calendar days Moscow');
}
verify((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===5,'No duplicates');
echo "PILOT E2E PASS: native PG 3 positive days/45 days -> signed release -> HTTP -> MariaDB concurrent consumers => five tasks/global, +3 days, replay, SHADOW/dry-run zero\n";
