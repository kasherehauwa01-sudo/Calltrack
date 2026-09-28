<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$analytics = (string) file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');
$callsApi = (string) file_get_contents($root.'/api/get_calls.php');

foreach (['get_calls.php?period=all&limit=0', 'Authorization', 'Bearer $token', 'managerJournalCalls', 'loadManagerJournal()'] as $required) {
    if (!str_contains($analytics, $required)) throw new RuntimeException("Android analytics does not load the server journal: {$required}");
}
foreach (['optionalAndroidUser($pdo)', 'androidManagerIdentity($androidUser)', "\$filters['user_phone']"] as $required) {
    if (!str_contains($callsApi, $required)) throw new RuntimeException("Calls API does not enforce Android manager scope: {$required}");
}
if (!str_contains($analytics, 'editable = false')) {
    throw new RuntimeException('Server journal rows can accidentally update unrelated local Room rows');
}

echo "android_analytics_manager_scope_test: OK\n";
