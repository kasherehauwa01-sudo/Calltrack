<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/analytics_migration_state.php';
function analyticsValidateManifest(array $manifest):array {
 $files=['20261020_manager_bindings.sql','20261020_analytics_tasks.sql','20261020_analytics_task_policy.sql','20261022_analytics_budget.sql','20261023_analytics_daily_budget.sql'];
 if(($manifest['contract_version']??null)!==2||array_keys($manifest['migrations']??[])!==$files)throw new RuntimeException('Unknown migration contract');
 $inherited=[];
 foreach($manifest['migrations'] as $file=>$m){
  if(!is_string($m['sha256']??null)||!hash_equals($m['sha256'],hash_file('sha256',__DIR__.'/../database/'.$file)))throw new RuntimeException('Reviewed SQL checksum differs: '.$file);
  $tables=[];$seeds=[];
  foreach(migrationStatements(file_get_contents(__DIR__.'/../database/'.$file)) as $sql){
   if(preg_match('/^CREATE TABLE ([a-z_]+)/i',$sql,$a)){
    $tables[]=$a[1];
    // Only these reviewed CREATEs inherit the database default, not a charset default.
    if(preg_match('/\) ENGINE=InnoDB$/D',$sql))$inherited[]=$a[1];
   }
   elseif(preg_match('/^INSERT INTO ([a-z_]+)/i',$sql,$a))$seeds[]=$a[1];
   else throw new RuntimeException('Unreviewed statement kind');
  }
  if(array_keys($m['tables']??[])!==$tables||($m['seeds']??null)!==$seeds)throw new RuntimeException('Incomplete migration contract: '.$file);
 }
 if(($manifest['database_collation_tables']??null)!==$inherited)throw new RuntimeException('Invalid database collation inheritance contract');
 return $manifest;
}
function analyticsMigrationManifest():array {
 return analyticsValidateManifest(json_decode(file_get_contents(__DIR__.'/analytics_schema_contract.json'),true,128,JSON_THROW_ON_ERROR));
}

function analyticsResolveCollationContract(PDO $db,array $manifest):array {
 $context=migrationRows($db,'SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
 $collation=$context[0]['DEFAULT_COLLATION_NAME']??null;
 if(($context[0]['DEFAULT_CHARACTER_SET_NAME']??null)!=='utf8mb4'||!in_array($collation,['utf8mb4_uca1400_ai_ci','utf8mb4_unicode_ci'],true))throw new RuntimeException('Unsupported database collation profile');
 $charsetDefault=$db->query("SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME='utf8mb4'")->fetchColumn();
 if($charsetDefault!=='utf8mb4_uca1400_ai_ci')throw new RuntimeException('Unsupported utf8mb4 charset default');
 // The baseline was captured on 11.8.6/UCA1400. Resolve only database-inherited
 // slots. Explicit ascii_bin/utf8mb4_bin and charset-only CREATEs stay exact.
 foreach($manifest['migrations'] as &$m)foreach($m['tables'] as $name=>&$table){
  if(!in_array($name,$manifest['database_collation_tables'],true))continue;
  if($table['table']['TABLE_COLLATION']!=='utf8mb4_uca1400_ai_ci')throw new RuntimeException('Unexpected inherited collation baseline');
  $table['table']['TABLE_COLLATION']=$collation;
  foreach($table['columns'] as &$column){
   if($column['CHARACTER_SET_NAME']==='utf8mb4'&&$column['COLLATION_NAME']==='utf8mb4_uca1400_ai_ci')$column['COLLATION_NAME']=$collation;
  }
  unset($column);
 }
 unset($m,$table);
 return $manifest;
}

function analyticsMigrate(PDO $db,bool $apply=false,?callable $afterStep=null):array {
 if($apply&&$db->inTransaction())return ['state'=>'BLOCKED','issues'=>['OPEN_TRANSACTION_FORBIDDEN']];
 if($apply&&(int)$db->query('SELECT @@autocommit')->fetchColumn()!==1)return ['state'=>'BLOCKED','issues'=>['AUTOCOMMIT_REQUIRED_FOR_EXPLICIT_DDL']];
 if(!str_contains((string)$db->query('SELECT VERSION()')->fetchColumn(),'MariaDB'))throw new RuntimeException('Only MariaDB contract verified');
 if((int)$db->query('SELECT @@foreign_key_checks')->fetchColumn()!==1||(int)$db->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)return ['state'=>'BLOCKED','issues'=>['CONSTRAINT_CHECKS_DISABLED']];
 $db->exec('SET SESSION max_statement_time=10');$db->exec('SET SESSION lock_wait_timeout=2');$db->exec('SET SESSION innodb_lock_wait_timeout=2');
 $name='analytics-migrations:'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
 $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->execute([$name]);if((int)$s->fetchColumn()!==1)return ['state'=>'BLOCKED','issues'=>['MIGRATION_BUSY']];
 $operations=[];$stage="PREFLIGHT";
 try {
  $manifest=analyticsMigrationManifest();$stage='COLLATION_PROFILE';
  $manifest=analyticsResolveCollationContract($db,$manifest);$stage='PREFLIGHT';$initial=migrationInspect($db,$manifest);
  if(!$apply||in_array($initial['state'],['BLOCKED','SCHEMA_DRIFT','APPLIED_AND_VERIFIED'],true))return $initial+['operations'=>[]];
  foreach($manifest['migrations'] as $file=>$m){
   $stage=$file;
   foreach(migrationStatements(file_get_contents(__DIR__.'/../database/'.$file)) as $sql){
    if(preg_match('/^CREATE TABLE ([a-z_]+)/i',$sql,$match)){
     $table=$match[1];if(!isset($m['tables'][$table]))throw new RuntimeException('Unreviewed table');
     $actual=migrationTable($db,$table);
     if($actual!==null){if($actual!==$m['tables'][$table])throw new RuntimeException('Schema changed concurrently');continue;}
    }elseif(preg_match('/^INSERT INTO ([a-z_]+)/i',$sql,$match)){
     $table=$match[1];if(!in_array($table,$m['seeds'],true))throw new RuntimeException('Unreviewed seed');
     $state=migrationSeedState($db,$table);if($state==='VALID')continue;
     if($state!=='MISSING_SAFE')throw new RuntimeException('Unsafe seed state; no defaults inserted');
    }else throw new RuntimeException('Unsupported migration statement');
    $stage=$file.':'.$table;
    $db->exec($sql);$operations[]=['migration'=>$file,'table'=>$table,'operation'=>str_starts_with($sql,'CREATE')?'CREATE':'SEED'];
    if($afterStep)$afterStep(count($operations));
   }
   $check=migrationInspect($db,$manifest);if(in_array($check['state'],['BLOCKED','SCHEMA_DRIFT'],true))return $check+['operations'=>$operations];
  }
  return migrationInspect($db,$manifest)+['operations'=>$operations];
 }catch(Throwable $e){return ['state'=>'BLOCKED','issues'=>['STEP_FAILED:'.$stage],'error_class'=>get_class($e),'operations'=>$operations];}
 finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$name]);}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 $options=array_slice($argv,1);
 if(array_diff($options,['--inspect','--apply','--confirm-maintenance'])||(in_array('--inspect',$options,true)&&in_array('--apply',$options,true))){fwrite(STDERR,"BLOCKED: invalid or ambiguous options\n");exit(1);}
 $apply=in_array('--apply',$argv,true);
 if($apply&&!in_array('--confirm-maintenance',$argv,true)){fwrite(STDERR,"BLOCKED: explicit maintenance confirmation required; all writers/consumers must be stopped\n");exit(1);}
 try{
  $dsn=getenv('ANALYTICS_MIGRATION_DSN');if(!$dsn||!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Explicit ANALYTICS_MIGRATION_DSN required');
  $db=new PDO($dsn,getenv('ANALYTICS_MIGRATION_USER')?:null,getenv('ANALYTICS_MIGRATION_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $result=analyticsMigrate($db,$apply);echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";exit($result['state']==='APPLIED_AND_VERIFIED'?0:2);
 }catch(Throwable $e){echo json_encode(['state'=>'BLOCKED','issues'=>['MIGRATION_FAILED'],'error_class'=>get_class($e)],JSON_THROW_ON_ERROR)."\n";exit(1);}
}
