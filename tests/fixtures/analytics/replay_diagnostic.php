<?php
declare(strict_types=1);
/** Offline acceptance replay. No getPdo(), cache lookup, transport, or real client/user bindings. */
if(PHP_SAPI!=='cli'||getenv('CALLTRACK_TEST_FIXTURES')!=='1'||(getenv('ANALYTICS_TASK_TEST_DSN')?:'sqlite::memory:')!=='sqlite::memory:')throw new RuntimeException('Offline in-memory fixture only');
require_once __DIR__.'/../../../api/analytics_integration.php';
require_once __DIR__.'/database.php';
$file=$argv[1]??'';
if(!is_file($file)||filesize($file)>16*1024*1024)throw new RuntimeException('Invalid diagnostic input');
$input=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
if(($input['diagnostic_only']??false)!==true||!is_array($input['events']??null)||count($input['events'])>1000)throw new RuntimeException('Diagnostic snapshot required');
$pdo=analyticsTestDatabase();$stages=[];$reasons=[];$decisions=[];
foreach($input['events'] as $event){
    if(($event['mode']??'')!=='SHADOW'||($event['automation_allowed']??true)!==false||($event['run']['status']??'')!=='DIAGNOSTIC'||($event['run']['source_decision']['accepted_data_through']??null)!==null)throw new RuntimeException('Cannot promote diagnostic evidence');
    $result=processAnalyticsEvent($pdo,$event,null,[],'dry_run',time());
    $stages[$event['signal_type']]=($stages[$event['signal_type']]??0)+1;
    $decisions[$result['decision']]=($decisions[$result['decision']]??0)+1;
    foreach($result['reasons'] as $reason)$reasons[$reason]=($reasons[$reason]??0)+1;
}
foreach($input['events'] as $event)processAnalyticsEvent($pdo,$event,null,[],'dry_run',time());
$count=(int)$pdo->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn();
if($count!==count($input['events']))throw new RuntimeException('Replay duplicated receipts');
foreach(['manager_tasks','manager_task_events','analytics_open_task_slots','analytics_client_bindings','client_manager_bindings'] as $table){
    if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Unexpected task/binding side effect');
}
if((int)$pdo->query('SELECT approved FROM analytics_task_policy WHERE id=1')->fetchColumn()!==0)throw new RuntimeException('Policy was approved');
echo json_encode(['diagnostic_only'=>true,'as_of'=>$input['as_of'],'csv_sha256'=>$input['csv_sha256'],
    'events'=>$count,'stages'=>$stages,'decisions'=>$decisions,'reasons'=>$reasons,
    'replay_deduplicated'=>true,'tasks_created'=>0,'task_events'=>0,'bindings_created'=>0,'automation_allowed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
