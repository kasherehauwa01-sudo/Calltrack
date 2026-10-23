<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/analytics_migrate.php';
require_once __DIR__.'/../api/web_auth.php';
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('ANALYTICS_MIGRATION_TEST_DSN')!=='mysql:host=analytics-test-mariadb;dbname=analytics_http_test')throw new RuntimeException('Explicit isolated MariaDB fixture required');
function collationPdo(string $name):PDO {
 return new PDO('mysql:host=analytics-test-mariadb;dbname='.$name,getenv('ANALYTICS_MIGRATION_USER'),getenv('ANALYTICS_MIGRATION_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
$admin=collationPdo('analytics_http_test');$created=[];$assertions=0;
function verifyCollation(bool $ok,string $why):void{global $assertions;$assertions++;if(!$ok)throw new RuntimeException($why);}
function collationSnapshot(PDO $db,array $tables):array {
 $out=[];foreach($tables as $table)$out[$table]=['definition'=>migrationTable($db,$table),'rows'=>$db->query('SELECT * FROM `'.$table.'`')->fetchAll()];return $out;
}
try {
 verifyCollation(str_starts_with((string)$admin->query('SELECT VERSION()')->fetchColumn(),'11.8.6-MariaDB'),'Test exact MariaDB 11.8.6');
 $manifest=analyticsMigrationManifest();
 foreach(['utf8mb4_uca1400_ai_ci','utf8mb4_unicode_ci'] as $collation){
  $name='analytics_collation_test_'.bin2hex(random_bytes(6));$created[]=$name;
  $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE '.$collation);$db=collationPdo($name);
  $db->exec('SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci');
  ensureWebAuthTables($db);
  echo json_encode($db->query('SELECT VERSION() AS version,@@collation_server AS server,@@collation_database AS db,@@collation_connection AS connection')->fetch())."\n";
  $baseTables=[];
  foreach(array_slice($manifest['migrations'],0,3,true) as $file=>$m){$db->exec(file_get_contents(__DIR__.'/../database/'.$file));$baseTables=array_merge($baseTables,array_keys($m['tables']));}
  $before=collationSnapshot($db,$baseTables);
  $legacy=migrationInspect($db,$manifest);
  if($collation==='utf8mb4_unicode_ci'){
   verifyCollation($legacy['state']==='SCHEMA_DRIFT','Original false positive reproduced');
   verifyCollation(count($legacy['issues'])===5,'Exactly five inherited base tables differ');
  }
  $inspect=analyticsMigrate($db);
  verifyCollation($inspect['state']==='PARTIALLY_APPLIED','Base accepted, budgets not yet applied');
  verifyCollation(collationSnapshot($db,$baseTables)===$before,'Inspect changes no definitions/data');
  verifyCollation(migrationTable($db,'analytics_task_budget')===null,'Inspect creates no budget');
  $resolved=analyticsResolveCollationContract($db,$manifest);
  foreach($manifest['migrations'] as $m)foreach($m['tables'] as $table=>$definition){
   if(in_array($table,$manifest['database_collation_tables'],true))continue;
   $file=array_search($m,$manifest['migrations'],true);
   verifyCollation($resolved['migrations'][$file]['tables'][$table]===$definition,'Explicit charset/collation unchanged '.$table);
  }
  $result=analyticsMigrate($db,true);
  verifyCollation($result['state']==='APPLIED_AND_VERIFIED'&&count($result['operations'])===9,'Create exactly missing budget statements');
  verifyCollation(collationSnapshot($db,$baseTables)===$before,'No old tables rebuilt or rows rewritten');
  foreach($resolved['migrations'] as $m)foreach($m['tables'] as $table=>$expected)verifyCollation(migrationTable($db,$table)===$expected,'Every physical definition verified '.$table);
  $again=analyticsMigrate($db,true);verifyCollation($again['state']==='APPLIED_AND_VERIFIED'&&$again['operations']===[],'Repeat has no operations');
  verifyCollation((int)$db->query('SELECT approved FROM analytics_task_policy')->fetchColumn()===0,'Policy stays unapproved');
  verifyCollation((int)$db->query('SELECT SUM(enabled) FROM analytics_task_type_policy')->fetchColumn()===0,'Types stay disabled');
  verifyCollation((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'No tasks');
  // An individually allowed collation in the WRONG inherited slot is still drift.
  $wrong=$collation==='utf8mb4_unicode_ci'?'utf8mb4_uca1400_ai_ci':'utf8mb4_unicode_ci';
  $db->exec('ALTER TABLE analytics_task_policy COLLATE '.$wrong);
  verifyCollation(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Wrong table default rejected');
  $db->exec('ALTER TABLE analytics_task_policy COLLATE '.$collation);
  $db->exec('ALTER TABLE analytics_sync_state MODIFY current_run_id VARCHAR(36) COLLATE '.$wrong.' NULL');
  verifyCollation(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Wrong inherited text collation rejected');
  $db->exec('ALTER TABLE analytics_sync_state MODIFY current_run_id VARCHAR(36) COLLATE '.$collation.' NULL');
  $db->exec('ALTER TABLE analytics_client_bindings MODIFY client_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL');
  verifyCollation(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Explicit utf8mb4_bin cannot change');
  $db->exec('ALTER TABLE analytics_client_bindings MODIFY client_id VARCHAR(255) COLLATE utf8mb4_bin NULL');
  $db->exec('ALTER TABLE analytics_sync_state MODIFY current_run_id VARCHAR(36) CHARACTER SET latin1 NULL');
  verifyCollation(analyticsMigrate($db,true)['state']==='SCHEMA_DRIFT','Wrong charset rejected');
  $db->exec('ALTER TABLE analytics_sync_state MODIFY current_run_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE '.$collation.' NULL');
  verifyCollation(analyticsMigrate($db)['state']==='APPLIED_AND_VERIFIED','Known shape restored only in disposable test');
  $allTables=[];foreach($resolved['migrations'] as $m)$allTables=array_merge($allTables,array_keys($m['tables']));
  $before=collationSnapshot($db,$allTables);
  $db->exec("SET SESSION character_set_collations='utf8mb4=utf8mb4_unicode_ci'");
  verifyCollation(analyticsMigrate($db,true)['state']==='BLOCKED','Unsupported charset default refused before DDL');
  verifyCollation(collationSnapshot($db,$allTables)===$before,'Charset default refusal changes nothing');
  $db->exec("SET SESSION character_set_collations='utf8mb4=utf8mb4_uca1400_ai_ci'");
  $admin->exec('ALTER DATABASE `'.$name.'` COLLATE utf8mb4_general_ci');
  $blocked=analyticsMigrate($db,true);
  verifyCollation($blocked['state']==='BLOCKED'&&$blocked['issues']===['STEP_FAILED:COLLATION_PROFILE'],'Unreviewed DB default refused');
  verifyCollation(collationSnapshot($db,$allTables)===$before,'No mutations on unsupported profile');
 }
 $bad=$manifest;$bad['database_collation_tables'][]='manager_tasks';$rejected=false;
 try{analyticsValidateManifest($bad);}catch(RuntimeException $e){$rejected=true;}verifyCollation($rejected,'Cannot broaden inherited slots to explicit charset tables');
 $bad=$manifest;$bad['migrations']['20261022_analytics_budget.sql']['sha256']=str_repeat('0',64);$rejected=false;
 try{analyticsValidateManifest($bad);}catch(RuntimeException $e){$rejected=true;}verifyCollation($rejected,'SQL checksum remains enforced');
 echo 'PASS analytics_migration_collation_test: '.$assertions." assertions; both database profiles, strict drift and preservation\n";
}finally{foreach($created as $name)$admin->exec('DROP DATABASE `'.$name.'`');}
