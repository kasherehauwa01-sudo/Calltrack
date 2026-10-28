<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../api/config.php';
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/../api/analytics_signed_evidence.php';
try{
 $keys=[];foreach(['CALLTRACK_UPSTREAM_PUBLIC_KEYS','CALLTRACK_BINDINGS_PUBLIC_KEYS'] as $variable){try{$keys[$variable]=count(analyticsEvidenceKeys($variable));}catch(Throwable $e){$keys[$variable]='INVALID';}}
 echo json_encode(['php'=>PHP_VERSION,'sodium'=>extension_loaded('sodium'),'mode'=>analyticsIntegrationMode(),'feed'=>getenv('CALLTRACK_ANALYTICS_FEED')?:'shadow','key_counts'=>$keys,'export_token_configured'=>strlen(getenv('CALLTRACK_BINDINGS_EXPORT_TOKEN')?:'')>=32],JSON_THROW_ON_ERROR).PHP_EOL;
 $db=getPdo();$db->exec('SET SESSION TRANSACTION READ ONLY');$db->exec('SET SESSION max_statement_time=5');$db->exec('SET SESSION lock_wait_timeout=1');$db->beginTransaction();
 try{
  if((int)$db->query('SELECT @@tx_read_only')->fetchColumn()!==1)throw new RuntimeException('Read-only transaction required');
  foreach([
   'server'=>'SELECT VERSION() AS version,@@collation_server AS server_collation,@@collation_database AS database_collation,@@collation_connection AS connection_collation',
   'policy'=>'SELECT id,approved,revision,min_confidence FROM analytics_task_policy WHERE id=1',
   'types'=>'SELECT signal_type,enabled,priority,due_days FROM analytics_task_type_policy ORDER BY signal_type',
   'budget'=>'SELECT id,approved,max_tasks,used_tasks,revision FROM analytics_task_budget WHERE id=1',
   'daily_policy'=>'SELECT id,policy_version,timezone,max_tasks FROM analytics_daily_budget_policy WHERE id=1',
   'approved_cursor'=>'SELECT publication_sequence,current_run_id,current_sequence,after_change FROM analytics_automation_sync_state WHERE id=1',
  ] as $name=>$sql)echo json_encode([$name=>$db->query($sql)->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR).PHP_EOL;
 }finally{if($db->inTransaction())$db->rollBack();}
}catch(Throwable $e){fwrite(STDERR,'READONLY CHECK BLOCKED: '.get_class($e).'; no repair attempted'.PHP_EOL);exit(1);}
