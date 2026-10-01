<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/add_call.php');
$migration=file_get_contents($root.'/database/20261001_add_max_call_fields.sql');
$entity=file_get_contents($root.'/app/src/main/java/com/example/calltrack/data/local/CallEntity.kt');
$ui=file_get_contents($root.'/analizmop/index.html');
$repository=file_get_contents($root.'/app/src/main/java/com/example/calltrack/data/repository/CallRepository.kt');
$recovery=file_get_contents($root.'/app/src/main/java/com/example/calltrack/service/CalltrackRecoveryManager.kt');
$database=file_get_contents($root.'/app/src/main/java/com/example/calltrack/data/local/CallDatabase.kt');
$analytics=file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');
foreach(['source_event_id','contact_resolution_status','answered_at','ringing_duration_seconds'] as $field){
    if(!str_contains($api,$field)||!str_contains($migration,$field))throw new RuntimeException("MAX field missing: $field");
}
if(!str_contains($api,'ON DUPLICATE KEY UPDATE')||!str_contains($migration,'UNIQUE KEY uk_source_event'))throw new RuntimeException('MAX idempotency missing');
foreach(["\$source==='max'&&!\$androidUser","\$source==='max'&&\$sourceEventId===''",'Для MAX-звонка требуется авторизация'] as $required)if(!str_contains($api,$required))throw new RuntimeException("MAX auth contract missing: $required");
foreach(['sourceEventId','contactResolutionStatus','ringingDurationSeconds'] as $field)if(!str_contains($entity,$field))throw new RuntimeException("Room MAX field missing: $field");
if(!str_contains($ui,"call.source==='max'?'MAX · ':''"))throw new RuntimeException('MAX timeline marker missing');
if(substr_count($repository,'private fun sqlDateTime(')!==1)throw new RuntimeException('sqlDateTime must be declared once');
if(substr_count($analytics,'private fun loadContactTimeline(')!==1)throw new RuntimeException('loadContactTimeline must be declared once');
if(str_contains($database,'fallbackToDestructiveMigration'))throw new RuntimeException('Room must not destroy call data during migration');
foreach(['suspend fun syncPending(): Boolean','sourceEventId = entity.sourceEventId'] as $required)if(!str_contains($repository,$required))throw new RuntimeException("MAX retry contract missing: $required");
foreach(['if (completed) Result.success() else Result.retry()','CalltrackRecoveryManager.canSync(app)'] as $required)if(!str_contains($recovery,$required))throw new RuntimeException("MAX WorkManager retry missing: $required");
echo "max_call_integration_test: OK\n";
