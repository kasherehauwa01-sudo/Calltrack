<?php
declare(strict_types=1);
// Explicit isolated helper for the cross-project admin-review regression only.
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='analytics_http_test')throw new RuntimeException('Isolated review fixture required');
require_once __DIR__.'/../api/analytics_sync.php';
$pdo=getPdo();$action=$argv[1]??'inspect';
if($action==='configure_policy'){
 $event=json_decode(file_get_contents('/fixtures/native-approved-event.json'),true,64,JSON_THROW_ON_ERROR);$now=time();
 $e=['upstream_confirmed'=>true,'complete_through'=>$event['as_of'],'verified_at'=>gmdate('c',$now-30),'valid_until'=>gmdate('c',$now+600),'source_revision'=>(string)$event['run']['source_revision']];
 $cache=clientCatalogRevision();$scope=$event['run']['scope'];
 $policy=['approved_scope_policies'=>[$scope['policy']],'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($scope),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))], 'approved_generators'=>[$event['generator_version']],'max_signal_age_seconds'=>600,'sales'=>$e,'clientsvr'=>$e+['cache_revision'=>$cache]];
 file_put_contents('/tmp/review-policy.json',json_encode($policy,JSON_THROW_ON_ERROR));echo "synthetic policy evidence ready\n";exit;
}
if($action==='sync_shadow'||$action==='sync_approved'){
 putenv('CALLTRACK_ANALYTICS_FEED='.($action==='sync_shadow'?'shadow':'approved_churn'));
 echo json_encode(syncAnalytics($pdo,'dry_run',analyticsIntegrationPolicy($pdo)),JSON_THROW_ON_ERROR).PHP_EOL;exit;
}
if($action==='process_enabled'||$action==='process_disabled'){
 $event=json_decode(file_get_contents('/fixtures/native-approved-event.json'),true,64,JSON_THROW_ON_ERROR);
 $binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));
 $client=$binding?(analyticsDirectoryRecords([(string)$binding['client_id']])[(string)$binding['client_id']]??null):null;
 putenv('CALLTRACK_ANALYTICS_MODE='.($action==='process_enabled'?'enabled':'disabled'));
 echo json_encode(processAnalyticsEvent($pdo,$event,$client,analyticsIntegrationPolicy($pdo),analyticsIntegrationMode(),time()),JSON_THROW_ON_ERROR).PHP_EOL;exit;
}
if($action!=='inspect')throw new InvalidArgumentException('Unknown fixture action');
echo json_encode(['tasks'=>(int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn(),'bindings'=>(int)$pdo->query('SELECT COUNT(*) FROM analytics_client_bindings')->fetchColumn(),'managers'=>(int)$pdo->query('SELECT COUNT(*) FROM client_manager_bindings')->fetchColumn(),'correct_tasks'=>(int)$pdo->query("SELECT COUNT(*) FROM manager_tasks WHERE client_id='client-100' AND assignee_id=3 AND family='INACTIVITY'")->fetchColumn()],JSON_THROW_ON_ERROR).PHP_EOL;
