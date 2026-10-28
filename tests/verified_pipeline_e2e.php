<?php
declare(strict_types=1);
/** Synthetic fixture controller; never loads a working DSN or signer. */
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('ANALYTICS_TASK_TEST_DSN')!=='mysql:host=analytics-test-mariadb;dbname=analytics_sync_test'||!str_starts_with(getenv('CALLTRACK_STORAGE_DIR')?:'', '/tmp/verified-pipeline-'))throw new RuntimeException('Dedicated isolated stand required');
require_once __DIR__.'/../api/config.php';
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/../api/analytics_verified_bindings.php';
require_once __DIR__.'/fixtures/analytics/database.php';
$config=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$mode=$argv[1]??'';
function verifiedCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$admin=['id'=>1,'role'=>'admin'];$storage=getenv('CALLTRACK_STORAGE_DIR');clientsEnsureDirectory($storage);
putenv('CALLTRACK_BINDINGS_SIGNING_KEY_FILE='.$storage.'/signer.key');putenv('CALLTRACK_BINDINGS_SIGNING_KEY_ID=fixture-calltrack');
if($mode==='prepare'){
 $db=analyticsTestDatabase();$db->exec("INSERT INTO web_users(id,display_name,login,pin_hash,role,is_active) VALUES(6,'Fixture manager six','six@example.test','synthetic-only','manager',1)");
 saveManagerBinding($db,$admin,'Fixture owner three',3,null);saveManagerBinding($db,$admin,'Fixture owner six',6,null);
 clientsEnsureDirectory(dirname(clientsSqliteFile()));$catalog=clientsOpenSqlite();
 foreach($config['cards'] as $card)clientsSqliteUpsert($catalog,$card,$card['id']);$catalog=null;
 $key=sodium_crypto_sign_keypair();$file=$storage.'/signer.key';file_put_contents($file,base64_encode(sodium_crypto_sign_secretkey($key)));chmod($file,0600);
 $pub=base64_encode(sodium_crypto_sign_publickey($key));$receipts=[];$scope=null;
 $page=salesJournalRequest('GET','/api/integrations/calltrack/analytics/runs/'.$config['run_id'].'/changes');
 foreach($page['items'] as $event){
  if($event['signal_type']!=='CHURN_RISK')continue;
  processAnalyticsEvent($db,$event,null,[],'dry_run',time());
  $clientKey=$event['subject']['key'];$scope=$event['run']['scope'];
  $history=salesJournalRequest('GET','/api/integrations/calltrack/analytics/identity-history?client_key='.rawurlencode($clientKey));
  $rows=[];$after=0;do{$h=salesJournalRequest('GET','/api/integrations/calltrack/analytics/identity-history/rows?client_key='.rawurlencode($clientKey).'&after='.$after);$rows=array_merge($rows,$h['items']);$after=$h['next_after'];}while($after!==null);
  // Reviewer independently checks every synthetic source row, not an injected VERIFIED flag.
  verifiedCheck($rows===$config['expected_history'][$clientKey],'Entire synthetic history reconciled');
  $group=analyticsReviewGroup($event,analyticsReviewDirectoryRows(),managerBindingRows($db),clientCatalogRevision());
  verifiedCheck(!$group['reasons'],'Synthetic owner group unambiguous');
  $request=['action'=>'begin','expected_revision'=>0,'reason'=>'Synthetic reviewer reconciled every independent input sale'];
  if(!empty($config['preliminary'])){analyticsPreparePreliminary($db,$event,$group,$history,true);}else{
  analyticsSaveGroupReview($db,$admin,$event,$group,$history,$request);
  $request=array_replace($request,['action'=>'confirm','expected_revision'=>1,'client_id'=>$config['selected'][$clientKey],'confirm_all_history'=>true,'confirm_identity'=>true]);
  analyticsSaveGroupReview($db,$admin,$event,$group,$history,$request);
  }
  $binding=analyticsBinding($db,analyticsSubjectKey($event['subject'],$scope));$receipts[]=analyticsExportReview($binding,$group,$history,time());
 }
 saveAnalyticsTaskPolicy($db,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>0.3,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>3],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
 saveAnalyticsTaskPolicy($db,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);saveAnalyticsBudget($db,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>2]);
 verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Review does not create tasks');
 if(!empty($config['preliminary']))verifiedCheck((int)$db->query("SELECT COUNT(*) FROM analytics_client_bindings WHERE status='VERIFIED'")->fetchColumn()===0,'No preliminary match becomes VERIFIED');
 echo json_encode(['receipts'=>$receipts,'public_keys'=>['fixture-calltrack'=>$pub],'cache_revision'=>clientCatalogRevision(),'scope'=>$scope],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
$db=new PDO(getenv('ANALYTICS_TASK_TEST_DSN'),getenv('ANALYTICS_TASK_TEST_USER'),getenv('ANALYTICS_TASK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
putenv('CALLTRACK_UPSTREAM_PUBLIC_KEYS='.json_encode($config['source_keys']));putenv('CALLTRACK_BINDINGS_PUBLIC_KEYS='.json_encode($config['binding_keys']));putenv('CALLTRACK_ANALYTICS_FEED=approved_churn');
$scope=$config['scope'];$policy=['upstream_issuer'=>'independent-fixture','required_sources'=>['synthetic-1c'],'max_manifest_age_seconds'=>7200,'approved_scope_policies'=>[$scope['policy']],
 'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($scope),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))],
 'approved_generators'=>['inactivity/aviator-churn-pilot/1'],'max_signal_age_seconds'=>3600,'cache_revision'=>clientCatalogRevision()];
if(($config['preliminary']??null)==='trusted-import')$policy+=['source_trust_mode'=>'TRUSTED_IMPORT','import_trust_approval_id'=>'synthetic-owner-decision-1'];
$publication=salesJournalRequest('GET',analyticsFeedPath('/api/integrations/calltrack/analytics/publications?page_size=1'));
$rid=$publication['items'][0]['run_id'];$page=salesJournalRequest('GET',analyticsFeedPath('/api/integrations/calltrack/analytics/runs/'.$rid.'/changes'));$events=$page['items'];
if($mode==='worker'){
 $event=$events[0];if(!empty($config['preliminary']))$event['signal_id']='synthetic-preliminary-concurrent-'.($argv[2]??'0');$client=analyticsDirectoryRecords([$event['subject']['key']])[$event['subject']['key']];
 for($i=0;$i<5;$i++)try{echo json_encode(processAnalyticsEvent($db,$event,$client,$policy,'enabled',time()));exit;}catch(PDOException $e){if(!in_array($e->getCode(),['40001','23000'],true))throw $e;usleep(20000);}exit(1);
}
if($mode==='consume_next'){
 verifiedCheck(count($events)===2,'Revoked client excluded from the new native publication');
 // Isolated crash fixture: committed receipts with a still-active previous-run cursor.
 $db->prepare('UPDATE analytics_automation_sync_state SET publication_sequence=0,current_run_id=?,current_sequence=1,after_change=1 WHERE id=1')->execute([$config['previous_release']]);
 $r=syncAnalytics($db,'enabled',$policy,null,5);verifiedCheck(($r['retired']??0)===1,'Terminal superseded run retired safely');
 verifiedCheck((int)$db->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()===2,'Current publication delivered after terminal retirement');
 foreach($events as $event){verifiedCheck($event['revision']===2,'Transport revision advances even if SHADOW revision did not');$s=$db->prepare('SELECT signal_revision,reasons_json FROM analytics_inbox WHERE signal_key=?');$s->execute([analyticsSignalKey($event)]);$inbox=$s->fetch();verifiedCheck((int)$inbox['signal_revision']===2,'Changed proof accepted as a new delivery revision');}
 verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'Fresh source/lease does not duplicate existing episodes');
 verifiedCheck((int)$db->query('SELECT used_tasks FROM analytics_task_budget')->fetchColumn()===2,'No extra budget consumed on refresh/replay');
 echo json_encode(['status'=>'PASS','retired'=>$r['retired'],'tasks'=>2]);exit;
}
verifiedCheck(count($events)===3,'Three native approved CHURN events, two-task lifetime cap');
verifiedCheck(syncAnalytics($db,'disabled',$policy)['processed']===0,'Disabled consumer');
syncAnalytics($db,'dry_run',$policy);
verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Dry-run creates no task');
verifiedCheck((int)$db->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()===0,'Dry-run never consumes enabled checkpoint');
$configFile=$storage.'/fixture.json';file_put_contents($configFile,json_encode($config));chmod($configFile,0600);$children=[];
for($i=0;$i<2;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'worker',(string)$i],[0=>['file',$configFile,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);verifiedCheck(proc_close($p)===0,'Concurrent worker '.$err);}
verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'Exactly one task from duplicate concurrent delivery');
$r=syncAnalytics($db,'enabled',$policy);verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'Other manager gets one task');
syncAnalytics($db,'enabled',$policy);verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'Repeated feed no duplicates');
$tasks=$db->query('SELECT * FROM manager_tasks ORDER BY assignee_id')->fetchAll();verifiedCheck(array_map('intval',array_column($tasks,'assignee_id'))===[3,6],'Only approved pilot managers');
foreach($tasks as $task){verifiedCheck($task['family']==='INACTIVITY'&&$task['title']==='Риск ухода клиента'&&$task['priority']==='HIGH','Correct task category/title/priority');verifiedCheck(str_contains($task['description'],'Связаться с клиентом'),'Manager recommendation');}
// No signature/boolean manipulation, stale evidence, new purchase or changed review may create another task.
$event=$events[0];$client=analyticsDirectoryRecords([$event['subject']['key']])[$event['subject']['key']];$binding=analyticsBinding($db,analyticsSubjectKey($event['subject'],$scope));$cfg=array_replace($policy,analyticsTaskPolicyGateConfig(analyticsTaskPolicy($db)));
$budgetProbe=$events[2];$budgetClient=analyticsDirectoryRecords([$budgetProbe['subject']['key']])[$budgetProbe['subject']['key']];$budgetResult=processAnalyticsEvent($db,$budgetProbe,$budgetClient,$policy,'enabled',time());verifiedCheck(in_array('BUDGET_EXHAUSTED',$budgetResult['reasons'],true),'Third real engine event obeys exhausted budget');
foreach(['signature','expired','version','manager','purchase','unsigned','anchor','matching'] as $case){$e=$event;$b=$binding;$c=$client;
 if($case==='signature')$e['upstream_manifest']['signature']=base64_encode(str_repeat('x',64));
 if($case==='expired')$e['calculated_at']='2020-01-01T00:00:00Z';
 if($case==='version')$e['assignment']['binding_revision']++;
 if($case==='manager')$e['assignment']['manager_id']=4;
 if($case==='purchase')$c['last_purchase']=gmdate('Y-m-d');
 if($case==='unsigned')unset($e['binding_receipt']);
 if($case==='matching')$e['matching']=['status'=>'PRELIMINARY_MATCH','group_key'=>str_repeat('f',64)];
 if($case==='anchor')$e['last_purchase']=gmdate('Y-m-d');
 verifiedCheck(!analyticsGate($e,$b,$c,managerBindingRows($db),$cfg,time())['allowed'],'Gate blocks '.$case);
}
$sameEpisode=$event;$sameEpisode['signal_id']='negative-changed-id-same-episode';
$existing=processAnalyticsEvent($db,$sameEpisode,$client,$policy,'dry_run',time());verifiedCheck($existing['decision']==='TASK_EXISTS','Dry-run recognizes an existing episode even when event ID changes');
$t=$tasks[0];updateManagerTask($db,['id'=>(int)$t['assignee_id'],'role'=>'manager'],$t['id'],['version'=>1,'status'=>'DONE','result'=>'Synthetic contact completed']);
$sameEpisode['signal_id']='negative-another-id-after-completion';$existing=processAnalyticsEvent($db,$sameEpisode,$client,$policy,'enabled',time());verifiedCheck($existing['decision']==='TASK_EXISTS','Completed same purchase episode never reopened or duplicated');
verifiedCheck($db->query('SELECT status FROM manager_tasks WHERE id='.$db->quote($t['id']))->fetchColumn()==='DONE','Completion retained');
$reviewPayload=json_decode($event['binding_receipt']['payload_json'],true,32,JSON_THROW_ON_ERROR);$normal=$event;$normal['subject']=['kind'=>'normalized_name','version'=>1,'key'=>$reviewPayload['client_key']];
$group=analyticsReviewGroup($normal,analyticsReviewDirectoryRows(),managerBindingRows($db),clientCatalogRevision());
$history=salesJournalRequest('GET','/api/integrations/calltrack/analytics/identity-history?client_key='.rawurlencode($reviewPayload['client_key']));
analyticsSaveGroupReview($db,$admin,$normal,$group,$history,['action'=>'revoke','expected_revision'=>(int)$binding['revision'],'reason'=>'Synthetic primary reviewer revoked approval']);
$revocation=analyticsExportReviewRefusal(analyticsBinding($db,analyticsSubjectKey($normal['subject'],$scope)),time());
$out=processAnalyticsEvent($db,$event,$client,$policy,'enabled',time());verifiedCheck($out['decision']==='REVIEW','Revocation reviews existing task');
verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'No mutation/extra tasks after revocation');
verifiedCheck((int)$db->query('SELECT used_tasks FROM analytics_task_budget')->fetchColumn()===2,'Lifetime budget spends exactly once per task');
// Diagnostic progress across separate bounded invocations, never touching enabled cursor.
putenv('CALLTRACK_ANALYTICS_FEED=shadow');$shadowPage=salesJournalRequest('GET','/api/integrations/calltrack/analytics/runs/'.$event['provenance']['shadow_run_id'].'/changes');$shadow=$shadowPage['items'][0];$requested=[];
$transport=static function($method,$path,$body)use($shadow,&$requested){
 if(str_contains($path,'/publications?'))return ['items'=>[['sequence'=>1,'run_id'=>'synthetic-large-preview']],'next_after'=>null,'automation_allowed'=>false];
 parse_str((string)parse_url($path,PHP_URL_QUERY),$query);$after=(int)($query['after']??0);$requested[]=$after;$items=[];
 for($i=$after;$i<min(60,$after+50);$i++){$e=$shadow;$e['signal_id']='synthetic-preview-'.$i;$e['run']['id']='synthetic-large-preview';$items[]=$e;}
 $run=$shadow['run'];$run['id']='synthetic-large-preview';return ['run'=>$run,'items'=>$items,'next_after'=>$after===0?50:null,'automation_allowed'=>false];
};
syncAnalytics($db,'dry_run',[],$transport,1);syncAnalytics($db,'dry_run',[],$transport,1);
verifiedCheck($requested===[0,50],'Private preview resumes page two without consuming enabled state');
verifiedCheck((int)$db->query('SELECT publication_sequence FROM analytics_sync_state')->fetchColumn()===0,'Enabled shadow checkpoint unchanged by preview');
verifiedCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'Large diagnostic pass creates no task');
echo json_encode(['status'=>'PASS','tasks'=>array_map(fn($t)=>array_intersect_key($t,array_flip(['client_id','assignee_id','title','priority','due_at','description','family'])),$tasks),'revocation_receipt'=>$revocation, 'example_event'=>$events[0],'checks'=>'HTTP native feed; signed review; independent signature; concurrent replay; dry-run checkpoint; revocation; budgets; completed-episode dedupe'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
