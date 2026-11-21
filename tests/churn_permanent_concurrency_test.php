<?php
declare(strict_types=1);
if(getenv('ANALYTICS_TASK_TEST_DSN')!=='mysql:host=analytics-test-mariadb;dbname=analytics_race_test')throw new RuntimeException('Disposable race DB required');
if(($argv[1]??'')==='budget-worker'){
 require_once __DIR__.'/../api/analytics_sync.php';
 require_once __DIR__.'/../api/analytics_permanent.php';
 $ctx=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
 $event=json_decode($ctx['page']['events'][0]['event_json'],true,64,JSON_THROW_ON_ERROR);
 $event['signal_id']='budget-race-'.$argv[3];$event['subject']['key']='budget-race-'.$argv[3];
 $db=new PDO(getenv('ANALYTICS_TASK_TEST_DSN'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->beginTransaction();
 $db->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn(); // deliberately freeze an old snapshot
 file_put_contents($argv[2].'.ready-'.$argv[3],'ready');
 $deadline=microtime(true)+5;
 while(is_file($argv[2].'.barrier')){
  if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timed out');
  usleep(10000);clearstatcache();
 }
 $decision=null;
 for($attempt=0;$attempt<5;$attempt++){
  if(!$db->inTransaction())$db->beginTransaction();
  try{analyticsPermanentBudget($db,$event,true);$db->commit();$decision='reserved';break;}
  catch(DomainException $e){if($db->inTransaction())$db->rollBack();if($e->getMessage()!=='PERMANENT_DAILY_LIMIT')throw $e;$decision='blocked';break;}
  catch(PDOException $e){
   if($db->inTransaction())$db->rollBack();
   if(!in_array((string)$e->getCode(),['23000','40001'],true)
      && !((string)$e->getCode()==='HY000'&&(int)($e->errorInfo[1]??0)===1020))throw $e;
   // Re-run the WHOLE transaction with a fresh snapshot, as host redelivery does.
   usleep(100000);
  }
 }
 if($decision===null)throw new RuntimeException('Budget retry bound exceeded');
 file_put_contents($argv[2].'.result-'.$argv[3],$decision);
 exit(0);
}
if(($argv[1]??'')==='worker'){
 require_once __DIR__.'/../api/analytics_sync.php';
 require_once __DIR__.'/../api/analytics_permanent.php';
 require_once __DIR__.'/../api/analytics_verified_bindings.php';
 $ctx=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
 $db=new PDO(getenv('ANALYTICS_TASK_TEST_DSN'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 for($i=0;$i<5;$i++){
  try{
   $receipt=analyticsPermanentPage($db,$ctx['page'],'deliver',time());
   if(!in_array($receipt['receipts'][0]['decision'],['TASK_CREATED','TASK_EXISTS'],true))throw new RuntimeException('Race delivery blocked');
   exit(0);
  }catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','40001'],true)&&!((string)$e->getCode()==='HY000'&&(int)($e->errorInfo[1]??0)===1020))throw $e;}
  catch(DomainException $e){if($e->getMessage()!=='CONSUMER_BUSY')throw $e;}
  usleep(200000);
 }
 exit(1);
}
require_once __DIR__.'/../api/analytics_sync.php';
/** Real Ed25519 -> existing gate -> patched task transaction, synthetic SQLite only. */
require __DIR__.'/churn_permanent_budget_test.php';
require_once $root.'/api/analytics_integration.php';
require_once $root.'/api/analytics_verified_bindings.php';
$storage=sys_get_temp_dir().'/calltrack-churn-test-'.bin2hex(random_bytes(8));mkdir($storage,0700);
putenv('CALLTRACK_STORAGE_DIR='.$storage);
$directory=new PDO('sqlite:'.clientsSqliteFile());
$directory->exec('CREATE TABLE clients(client_id TEXT PRIMARY KEY,payload_json TEXT)');
$card=['id'=>'signed-client','name'=>'Synthetic Customer','phones'=>[], 'fields'=>['manager'=>'Signed Owner','status'=>'active']];
$directory->prepare('INSERT INTO clients VALUES(?,?)')->execute([$card['id'],json_encode($card,JSON_THROW_ON_ERROR)]);
$directory=null;
$admin=['id'=>1,'role'=>'admin'];saveManagerBinding($db,$admin,'Signed Owner',3,null);
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec("INSERT INTO web_users(id,display_name,role,is_active,login,pin_hash) VALUES(6,'Synthetic alternate manager','manager',1,'fixture6@example.test','synthetic-disabled-login')");
else $db->exec("INSERT INTO web_users VALUES(6,'Synthetic alternate manager','manager',1)");
$managers=managerBindingRows($db);$cache=clientCatalogRevision();
$scope=['policy'=>'aviatorov-wholesale-corporate/2','department'=>'Авиаторов','buyer_types'=>['Оптовик','Корпоративный']];
$nameEvent=['subject'=>['kind'=>'normalized_name','version'=>1,'key'=>'synthetic customer'],'run'=>['scope'=>$scope]];
$group=analyticsReviewGroup($nameEvent,[$card],$managers,$cache);
$history=['version'=>1,'department'=>'Авиаторов','normalization_version'=>1,'client_key'=>'synthetic customer',
 'sale_count'=>3,'history_hash'=>hash('sha256','independent synthetic purchase fixture'),'last_positive_purchase'=>'2026-07-01'];
$begin=analyticsSaveGroupReview($db,$admin,$nameEvent,$group,$history,['action'=>'begin','reason'=>'Synthetic independent review','expected_revision'=>0]);
$confirm=analyticsSaveGroupReview($db,$admin,$nameEvent,$group,$history,['action'=>'confirm','reason'=>'Synthetic independent review','expected_revision'=>$begin['revision'],
 'client_id'=>'signed-client','confirm_all_history'=>true,'confirm_identity'=>true]);
$subject=['kind'=>'external_id','version'=>1,'key'=>'signed-client'];
$binding=analyticsBinding($db,analyticsSubjectKey($subject,$scope));
$upstream=sodium_crypto_sign_keypair();$bindings=sodium_crypto_sign_keypair();
$keyfile=$storage.'/bindings.key';file_put_contents($keyfile,base64_encode(sodium_crypto_sign_secretkey($bindings)));chmod($keyfile,0600);
putenv('CALLTRACK_BINDINGS_SIGNING_KEY_FILE='.$keyfile);putenv('CALLTRACK_BINDINGS_SIGNING_KEY_ID=synthetic-bindings');
putenv('CALLTRACK_UPSTREAM_PUBLIC_KEYS='.json_encode(['synthetic-upstream'=>base64_encode(sodium_crypto_sign_publickey($upstream))]));
putenv('CALLTRACK_BINDINGS_PUBLIC_KEYS='.json_encode(['synthetic-bindings'=>base64_encode(sodium_crypto_sign_publickey($bindings))]));
$now=time();$day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->modify('-1 day')->format('Y-m-d');
$receipt=analyticsExportReview($binding,$group,$history,$now);
$source=['version'=>1,'issuer'=>'synthetic-independent-source','audience'=>'sales-journal+calltrack',
 'issued_at'=>gmdate('c',$now-1),'valid_until'=>gmdate('c',$now+600),
 'source_run_id'=>'synthetic-source-run','source_revision'=>7,'as_of'=>$day,'complete_through'=>$day,'complete'=>true,
 'clients_current'=>true,'clients_revision'=>'synthetic-clients-revision','clients_cache_revision'=>$cache,
 'source_revisions'=>['synthetic-source'=>'fixture-revision'],'fact_count'=>3,'facts_hash'=>hash('sha256','three independent purchase facts'),
 'public_scope_hash'=>hash('sha256',analyticsEvidenceJson($scope)),
 'assignments'=>[['subject_hash'=>hash('sha256',json_encode('synthetic customer',JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
 'client_id'=>'signed-client','manager_id'=>3,'revision'=>(int)$binding['revision']]],
 'review_groups'=>[['client_id'=>'signed-client','group_hash'=>$group['group_hash']]],'candidate_count'=>1,'reviewed_count'=>1];
$raw=analyticsEvidenceJson($source);
$manifest=['algorithm'=>'Ed25519','key_id'=>'synthetic-upstream','payload_json'=>$raw,
 'signature'=>base64_encode(sodium_crypto_sign_detached($raw,sodium_crypto_sign_secretkey($upstream)))];
$hash=hash('sha256',$raw);
$sourceDecision=['method'=>'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','coverage_status'=>'CONFIRMED','business_freshness'=>'CURRENT',
 'accepted_data_through'=>$day,'effective_as_of'=>$day,'source_revision'=>7,'proof_hash'=>$hash,'policy_revision'=>3,'valid_until'=>$source['valid_until']];
$event=['source'=>'sales-journal','signal_id'=>'signed-signal','revision'=>1,'status'=>'ACTIVE','family'=>'INACTIVITY','signal_type'=>'CHURN_RISK',
 'subject'=>$subject,'assignment'=>['client_id'=>'signed-client','manager_id'=>3,'binding_revision'=>(int)$binding['revision']],
 'score'=>70.0,'confidence'=>0.8,'last_purchase'=>'2026-07-01','as_of'=>$day,'calculated_at'=>gmdate('c',$now),
 'mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'analytical_eligible'=>true,'generator_version'=>'inactivity/aviator-churn-pilot/2',
 'metrics'=>['median_interval'=>90.0,'days_without_purchase'=>103],
 'run'=>['id'=>'synthetic-release','as_of'=>$day,'scope'=>$scope,'source_revision'=>7,'automation_allowed'=>true,'source_decision'=>$sourceDecision],
 'provenance'=>['shadow_run_id'=>'synthetic-source-run','proof_hash'=>$hash], 'upstream_manifest'=>$manifest,'binding_receipt'=>$receipt,
 'permanent_policy'=>['version'=>'churn-permanent/1','revision'=>2,'producer_revision'=>2,'daily_limit'=>2,'cooldown_days'=>30]];
$policy=['upstream_issuer'=>'synthetic-independent-source','max_manifest_age_seconds'=>3600,'required_sources'=>['synthetic-source'],
 'cache_revision'=>$cache,'approved_scope_policies'=>[$scope['policy']],
 'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($scope),JSON_UNESCAPED_UNICODE))],
 'approved_generators'=>['inactivity/aviator-churn-pilot/2'],'max_signal_age_seconds'=>86400,
 'min_confidence'=>0.5,'priorities'=>['CHURN_RISK'=>'HIGH'],'due_days_by_type'=>['CHURN_RISK'=>3],
 'policy_approved'=>true,'enabled_types'=>['CHURN_RISK']];
$client=['id'=>'signed-client','manager'=>'Signed Owner','manager_conflict'=>false,'status'=>'active'];
saveAnalyticsPermanentPolicy($db,['action'=>'enable','revision'=>2],'operator');
$db->exec('DELETE FROM analytics_permanent_reservations');
$gate=analyticsGate($event,$binding,$client,$managers,$policy,$now);
check($gate['allowed'],'genuine independent Ed25519 and reviewed receipt pass existing gate: '.json_encode($gate['reasons']));
foreach (['signature','identity','algorithm','manager','source'] as $failure) {
    $e=$event;$b=$binding;$c=$client;$p=$policy;
    if($failure==='signature')$e['upstream_manifest']['signature']=base64_encode(str_repeat('x',64));
    if($failure==='identity')$b['status']='REVIEW';
    if($failure==='algorithm')$p['approved_generators']=[];
    if($failure==='manager')$c['manager']='Unmapped owner';
    if($failure==='source')$e['run']['source_decision']['effective_as_of']='2000-01-01';
    check(!analyticsGate($e,$b,$c,$managers,$p,$now)['allowed'],$failure.' blocks without weakening gate');
}

$raw=json_encode($event,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
$page=['job_id'=>'race-job','release_id'=>$event['run']['id'],'events'=>[['event_id'=>1,'event_hash'=>hash('sha256',$raw),'event_json'=>$raw]]];
$policyFile=$storage.'/policy.json';file_put_contents($policyFile,json_encode($policy,JSON_THROW_ON_ERROR));chmod($policyFile,0600);
putenv('CALLTRACK_ANALYTICS_POLICY_FILE='.$policyFile);
// Failure after budget reservation must roll back task, reservation and inbox.
$db->exec("CREATE TRIGGER fail_once BEFORE INSERT ON manager_tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
try{analyticsPermanentPage($db,$page,'deliver',time());throw new RuntimeException('Failure injection did not fire');}
catch(PDOException $e){check($e->getCode()==='45000','expected injected SQL failure');}
check((int)$db->query("SELECT COUNT(*) FROM manager_tasks WHERE client_id='signed-client'")->fetchColumn()===0,'failed task not committed');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===0,'failed reservation not committed');
$db->exec('DROP TRIGGER fail_once');
// MariaDB 11.8 concurrency error must also roll back before sender retries.
$db->exec("CREATE TRIGGER fail_1020 BEFORE INSERT ON manager_tasks FOR EACH ROW SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1020,MESSAGE_TEXT='synthetic record changed'");
try{analyticsPermanentPage($db,$page,'deliver',time());throw new RuntimeException('1020 injection did not fire');}
catch(PDOException $e){check((int)($e->errorInfo[1]??0)===1020,'expected MariaDB 1020');}
check((int)$db->query("SELECT COUNT(*) FROM manager_tasks WHERE client_id='signed-client'")->fetchColumn()===0,'1020 task rolled back');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===0,'1020 reservation rolled back');
$db->exec('DROP TRIGGER fail_1020');

$context=$storage.'/race-context.json';file_put_contents($context,json_encode(['page'=>$page],JSON_THROW_ON_ERROR));chmod($context,0600);
$children=[];
foreach([1,2] as $ignored){$pipes=[];$children[]=proc_open([PHP_BINARY,__FILE__,'worker',$context],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
foreach($children as $child)check(is_resource($child)&&proc_close($child)===0,'concurrent worker recovers');
check((int)$db->query("SELECT COUNT(*) FROM manager_tasks WHERE client_id='signed-client'")->fetchColumn()===1,'exactly one automatic task');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===1,'exactly one permanent reservation');
$again=analyticsPermanentPage($db,$page,'deliver',time());
check($again['receipts'][0]['decision']==='TASK_EXISTS'&&$again['receipts'][0]['created_for_release']===true,'lost ACK preserves creation attribution');
echo "churn_permanent_concurrency_test: InnoDB failure rollback, retry, concurrent same signal and lost ACK OK\n";

// Two different signals share exactly one remaining slot, even with stale snapshots.
file_put_contents($context.'.barrier','hold');$children=[];
foreach([1,2] as $number){$pipes=[];$children[]=proc_open([PHP_BINARY,__FILE__,'budget-worker',$context,(string)$number],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
$deadline=microtime(true)+5;
while(!is_file($context.'.ready-1')||!is_file($context.'.ready-2')){
 if(microtime(true)>$deadline)throw new RuntimeException('Workers failed to enter snapshot barrier');
 usleep(10000);clearstatcache();
}
unlink($context.'.barrier');
foreach($children as $child)check(is_resource($child)&&proc_close($child)===0,'budget worker recovers');
$decisions=[file_get_contents($context.'.result-1'),file_get_contents($context.'.result-2')];sort($decisions);
check($decisions===['blocked','reserved'],'only one different signal gets final daily slot');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===2,'daily cap holds under old snapshots');
echo "churn_permanent_concurrency_test: different-signal daily-cap race under REPEATABLE READ OK\n";
