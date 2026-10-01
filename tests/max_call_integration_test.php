<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/add_call.php');
$migration=file_get_contents($root.'/database/20261001_add_max_call_fields.sql');
$entity=file_get_contents($root.'/app/src/main/java/com/example/calltrack/data/local/CallEntity.kt');
$ui=file_get_contents($root.'/analizmop/index.html');
foreach(['source_event_id','contact_resolution_status','answered_at','ringing_duration_seconds'] as $field){
    if(!str_contains($api,$field)||!str_contains($migration,$field))throw new RuntimeException("MAX field missing: $field");
}
if(!str_contains($api,'ON DUPLICATE KEY UPDATE')||!str_contains($migration,'UNIQUE KEY uk_source_event'))throw new RuntimeException('MAX idempotency missing');
foreach(['sourceEventId','contactResolutionStatus','ringingDurationSeconds'] as $field)if(!str_contains($entity,$field))throw new RuntimeException("Room MAX field missing: $field");
if(!str_contains($ui,"call.source==='max'?'MAX · ':''"))throw new RuntimeException('MAX timeline marker missing');
echo "max_call_integration_test: OK\n";
