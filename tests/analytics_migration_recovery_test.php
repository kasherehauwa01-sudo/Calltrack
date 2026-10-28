<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/analytics_migrate.php';
require_once __DIR__.'/../api/web_auth.php';
require_once __DIR__.'/../api/analytics_sync.php';
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('ANALYTICS_MIGRATION_TEST_DSN')!=='mysql:host=analytics-test-mariadb;dbname=analytics_http_test')throw new RuntimeException('Explicit isolated MariaDB fixture required');
function recoveryPdo(string $name='analytics_http_test'):PDO{return new PDO('mysql:host=analytics-test-mariadb;dbname='.$name,getenv('ANALYTICS_MIGRATION_USER'),getenv('ANALYTICS_MIGRATION_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
if(($argv[1]??'')==='worker'){
 if(!preg_match('/^analytics_migration_test_[a-f0-9]+$/D',$argv[2]??''))throw new RuntimeException('Bad test name');
 $db=recoveryPdo($argv[2]);$result=analyticsMigrate($db,true,fn($n)=>$n===1?usleep(800000):null);echo json_encode($result);exit;
}
$admin=recoveryPdo();$created=[];$assertions=0;
function verifyRecovery(bool $value,string $message):void{global $assertions;$assertions++;if(!$value)throw new RuntimeException($message);}
function freshRecovery():PDO{global $admin,$created;$collation=getenv('ANALYTICS_MIGRATION_TEST_COLLATION')?:'utf8mb4_uca1400_ai_ci';if(!in_array($collation,['utf8mb4_uca1400_ai_ci','utf8mb4_unicode_ci'],true))throw new RuntimeException('Unsupported test collation');$name='analytics_migration_test_'.bin2hex(random_bytes(6));$created[]=$name;$admin->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE '.$collation);$db=recoveryPdo($name);ensureWebAuthTables($db);return $db;}
function runBase(PDO $db):void{foreach(['20261020_manager_bindings.sql','20261020_analytics_tasks.sql','20261020_analytics_task_policy.sql'] as $file)$db->exec(file_get_contents(__DIR__.'/../database/'.$file));}
try{
 if(getenv('ANALYTICS_MIGRATION_HTTP_TEST')==='1')foreach(['analytics_migrate.php','analytics_migration_state.php'] as $file){
  @file_get_contents('http://127.0.0.1:8877/vr/calltrack/tools/'.$file.'?--apply+--confirm-maintenance',false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5]]));
  verifyRecovery(str_contains($http_response_header[0]??'',' 404 '),'No HTTP migration entrypoint '.$file);
 }
 $manifest=analyticsMigrationManifest();$bad=$manifest;$bad['contract_version']=999;
 $refused=false;try{analyticsValidateManifest($bad);}catch(RuntimeException $e){$refused=true;}verifyRecovery($refused,'Unknown contract blocked');
 $bad=$manifest;unset($bad['migrations']['20261023_analytics_daily_budget.sql']['tables']['analytics_daily_budget']);
 $refused=false;try{analyticsValidateManifest($bad);}catch(RuntimeException $e){$refused=true;}verifyRecovery($refused,'Incomplete contract blocked');
 $db=freshRecovery();verifyRecovery(analyticsMigrate($db)['state']==='NOT_APPLIED','Not applied');
 verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Clean chain');
 $again=analyticsMigrate($db,true);verifyRecovery($again['state']==='APPLIED_AND_VERIFIED'&&$again['operations']===[],'Verified no-op');
 // Every interruption point in both new migrations, including between CREATE and seed.
 $steps=0;foreach(['20261022_analytics_budget.sql','20261023_analytics_daily_budget.sql'] as $file)$steps+=count(migrationStatements(file_get_contents(__DIR__.'/../database/'.$file)));
 for($stop=1;$stop<=$steps;$stop++){
  $db=freshRecovery();runBase($db);
  $r=analyticsMigrate($db,true,function($n)use($stop){if($n===$stop)throw new RuntimeException('Synthetic interruption');});
  verifyRecovery($r['state']==='BLOCKED'&&count($r['operations'])===$stop,'Interrupted boundary '.$stop);
  $r=analyticsMigrate($db);verifyRecovery(in_array($r['state'],['PARTIALLY_APPLIED','APPLIED_AND_VERIFIED'],true),'Partial recognized '.$stop);
  verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Safe resume '.$stop);
 }
 // Partial schema: runtime automation remains closed; manual tasks survive resume.
 $db=freshRecovery();runBase($db);
 $db->exec("INSERT INTO web_users(id,display_name,login,pin_hash,role,is_active) VALUES(3,'Synthetic Manager','partial@example.test','synthetic-only','manager',1)");
 verifyRecovery(processAnalyticsEvent($db,[],null,[],'disabled',time())['decision']==='DISABLED','Partial disabled consumer');
 try{analyticsBudget($db);throw new LogicException('Missing budget accepted');}catch(PDOException $e){}
 $manual=createManagerTask($db,['id'=>3,'role'=>'admin'],['client_id'=>'partial-manual','assignee_id'=>3,'title'=>'Manual on partial']);
 verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Resume with existing manual task');
 $stmt=$db->prepare('SELECT source_signal_key FROM manager_tasks WHERE id=?');$stmt->execute([$manual['task']['id']]);verifyRecovery($stmt->fetchColumn()===null,'Manual remains manual');
 // Incompatible shape: refuse BEFORE any other missing-table creation.
 $db=freshRecovery();runBase($db);$db->exec('CREATE TABLE analytics_task_budget(id INT PRIMARY KEY)');
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Wrong structure blocked');
 verifyRecovery(migrationTable($db,'analytics_task_budget_events')===null,'No writes on drift');
 // Correct columns but missing constraints/indexes are not accepted.
 $db=freshRecovery();runBase($db);$sql=file_get_contents(__DIR__.'/../database/20261023_analytics_daily_budget.sql');
 $first=migrationStatements($sql)[0];$first=preg_replace('/,\s*CONSTRAINT ck_daily_policy_id CHECK \(id=1\)/','',$first);$db->exec($first);
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Missing CHECK blocked');
 $db=freshRecovery();verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','FK setup');
 $db->exec('ALTER TABLE analytics_daily_budget_reservations DROP FOREIGN KEY fk_daily_reservation_bucket');
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Missing foreign key blocked');
 $db=freshRecovery();verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Index setup');
 $db->exec('ALTER TABLE analytics_inbox DROP INDEX ix_analytics_decision');
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Missing index blocked');
 // Immutable source policy conflict is never overwritten.
 $db=freshRecovery();verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Setup');
 $db->exec("UPDATE analytics_daily_budget_policy SET timezone='UTC' WHERE id=1");
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Wrong daily seed blocked');
 verifyRecovery($db->query('SELECT timezone FROM analytics_daily_budget_policy')->fetchColumn()==='UTC','Conflicting policy untouched');
 // Existing, legitimately changed budgets and audit/task/binding rows remain byte-equivalent.
 $db=freshRecovery();verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Data setup');
 $db->exec("INSERT INTO web_users(id,display_name,login,pin_hash,role,is_active) VALUES(3,'Synthetic Manager','fixture@example.test','synthetic-only','manager',1)");
 $db->exec('UPDATE analytics_task_budget SET approved=1,max_tasks=10,used_tasks=2,revision=5');
 $db->exec("INSERT INTO analytics_daily_budget VALUES(1,'2026-10-23',2)");
 $db->exec("INSERT INTO analytics_daily_budget_reservations(reservation_key,policy_id,business_date) VALUES(REPEAT('a',64),1,'2026-10-23'),(REPEAT('b',64),1,'2026-10-23')");
 $db->exec("INSERT INTO manager_tasks(id,client_id,assignee_id,title,description,result,family,source_signal_key) VALUES('00000000-0000-4000-8000-000000000001','fixture',3,'Synthetic','','','INACTIVITY',REPEAT('a',64))");
 $db->exec("INSERT INTO manager_task_events(task_id,actor_id,event_type,data_json) VALUES('00000000-0000-4000-8000-000000000001',3,'CREATED','{}')");
 $db->exec("INSERT INTO client_manager_binding_events(designation_key,new_user_id,actor_id) VALUES(REPEAT('c',64),3,3)");
 $db->exec("INSERT INTO client_manager_bindings(designation_key,designation,web_user_id,updated_by) VALUES(REPEAT('c',64),'Synthetic Owner',3,3)");
 $db->exec("INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(REPEAT('d',64),'{}','fixture','REVIEW',0,'Synthetic evidence',3)");
 $tables=['client_manager_bindings','analytics_client_bindings','analytics_task_budget','analytics_daily_budget','analytics_daily_budget_reservations','manager_tasks','manager_task_events','client_manager_binding_events'];
 $snapshot=[];foreach($tables as $table)$snapshot[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
 verifyRecovery(analyticsMigrate($db,true)['state']==='APPLIED_AND_VERIFIED','Populated rerun');
 foreach($tables as $table)verifyRecovery($db->query('SELECT * FROM '.$table)->fetchAll()===$snapshot[$table],'Preserved '.$table);
 // Missing seed over operational history is not synthesized as a zero budget.
 $db->exec('DELETE FROM analytics_task_budget WHERE id=1');
 verifyRecovery(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Missing used budget blocked');
 verifyRecovery(!$db->query('SELECT 1 FROM analytics_task_budget')->fetchColumn(),'No counter reset');
 // Another connection cannot migrate while the cooperative lock is held.
 $db=freshRecovery();$other=recoveryPdo((string)$db->query('SELECT DATABASE()')->fetchColumn());
 $lock='analytics-migrations:'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
 $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);
 verifyRecovery(analyticsMigrate($other,true)['issues']===['MIGRATION_BUSY'],'Concurrent runner blocked');
 $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);verifyRecovery(analyticsMigrate($other,true)['state']==='APPLIED_AND_VERIFIED','Resume after lock release');
 // Two independent OS processes, not just connections, contend for the same lock.
 $db=freshRecovery();$name=(string)$db->query('SELECT DATABASE()')->fetchColumn();$pipes=[];
 $first=proc_open([PHP_BINARY,__FILE__,'worker',$name],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $lock='analytics-migrations:'.substr(hash('sha256',$name),0,40);$held=false;
 for($i=0;$i<100;$i++){$s=$admin->prepare('SELECT IS_USED_LOCK(?)');$s->execute([$lock]);if($s->fetchColumn()){$held=true;break;}usleep(10000);}
 verifyRecovery($held,'First process acquired lock');$p2=[];
 $second=proc_open([PHP_BINARY,__FILE__,'worker',$name],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$p2);
 $secondOut=stream_get_contents($p2[1]);$secondErr=stream_get_contents($p2[2]);verifyRecovery(proc_close($second)===0,'Second process exited');
 verifyRecovery(json_decode($secondOut,true)['issues']===['MIGRATION_BUSY'],'Concurrent process blocked');
 $firstOut=stream_get_contents($pipes[1]);$firstErr=stream_get_contents($pipes[2]);verifyRecovery(proc_close($first)===0,'First process completed');
 verifyRecovery(json_decode($firstOut,true)['state']==='APPLIED_AND_VERIFIED','First process verified');
 $db=freshRecovery();$db->beginTransaction();verifyRecovery(analyticsMigrate($db,true)['issues']===['OPEN_TRANSACTION_FORBIDDEN'],'Never implicitly commit caller transaction');$db->rollBack();
 $db=freshRecovery();$db->exec('SET SESSION foreign_key_checks=0');verifyRecovery(analyticsMigrate($db,true)['issues']===['CONSTRAINT_CHECKS_DISABLED'],'FK checks must stay on');
 echo 'PASS analytics_migration_recovery_test: '.$assertions.' assertions; '.$steps." interruption boundaries; old tasks/bindings/budgets preserved\n";
}finally{foreach($created as $name)$admin->exec('DROP DATABASE `'.$name.'`');}
