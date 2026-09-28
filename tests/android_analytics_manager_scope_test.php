<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$analytics = (string) file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');
$timelineApi = (string) file_get_contents($root.'/api/android_client_timeline.php');

foreach (['android_client_timeline.php?date_from=', 'Authorization', 'Bearer $token', 'managerJournalCalls', 'loadContactTimeline()', 'managerEmails', 'managerSales', 'HorizontalScrollView'] as $required) {
    if (!str_contains($analytics, $required)) throw new RuntimeException("Android analytics does not load the server journal: {$required}");
}
foreach (['currentAndroidUser($pdo)', 'androidManagerIdentity($user)', 'user_phone=:user_phone', 'manager_name=:manager', 'salesJournalCommunicationClients', 'sales_available'] as $required) {
    if (!str_contains($timelineApi, $required)) throw new RuntimeException("Timeline API does not enforce Android manager scope: {$required}");
}
if (!str_contains($analytics, 'TimelineUiEvent') || !str_contains($analytics, 'callEventLabel')) {
    throw new RuntimeException('Server journal is not rendered as a read-only timeline');
}

echo "android_analytics_manager_scope_test: OK\n";
