<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/App.kt');
$worker = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/service/CalltrackStabilityWorker.kt');
$service = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/service/CallTrackingService.kt');
$logger = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/logging/AppLogger.kt');
$manifest = (string)file_get_contents($root . '/app/src/main/AndroidManifest.xml');
$analytics = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');
$aboutLayout = (string)file_get_contents($root . '/app/src/main/res/layout/activity_about.xml');
$main = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$onboarding = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/onboarding/OnboardingFragment.kt');
$diagnostics = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/service/StabilityDiagnostics.kt');
$repository = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/data/repository/CallRepository.kt');
$callDao = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/data/local/CallDao.kt');

foreach (['PeriodicWorkRequestBuilder<CalltrackStabilityWorker>(15, TimeUnit.MINUTES)', 'ExistingPeriodicWorkPolicy.KEEP', 'repository.syncPending()', 'repository.sendUserTelemetry()'] as $expected) {
    if (!str_contains($worker, $expected)) throw new RuntimeException("Нет механизма восстановления: {$expected}");
}
if (!str_contains($app, 'CalltrackStabilityWorker.schedule(this)')) {
    throw new RuntimeException('Периодическая проверка не запускается при старте приложения');
}
if (str_contains($app, 'Thread.setDefaultUncaughtExceptionHandler')) {
    throw new RuntimeException('Application повторно заменяет системный crash handler');
}
foreach (['RECEIVE_BOOT_COMPLETED', '.service.BootReceiver'] as $expected) {
    if (!str_contains($manifest, $expected)) throw new RuntimeException("Нет восстановления после перезагрузки: {$expected}");
}
foreach (['REQUEST_IGNORE_BATTERY_OPTIMIZATIONS', 'ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS', 'isIgnoringBatteryOptimizations'] as $expected) {
    if (!str_contains($manifest . $main, $expected)) throw new RuntimeException("Нет подсказки для фоновой работы: {$expected}");
}
if (!str_contains($onboarding, 'Stage.BATTERY') || !str_contains($onboarding, 'Продолжить без разрешения')) {
    throw new RuntimeException('Запрос фоновой работы не включён в пошаговую первичную настройку');
}
if (!str_contains($service, 'runCatching { tracker.start() }') || !str_contains($logger, 'PRUNE_INTERVAL_MS')) {
    throw new RuntimeException('Не добавлена защита сервиса или экономное обслуживание журнала');
}
foreach (['service_heartbeat_at', 'tracker_event_at', 'call_capture_finished_at', 'sync_failed_detail', 'pending_calls', 'device_idle', 'battery_optimization_ignored'] as $expected) {
    if (!str_contains($diagnostics, $expected)) throw new RuntimeException("Чёрный ящик не сохраняет диагностический параметр: {$expected}");
}
foreach (['STABILITY_GAP', 'serviceHeartbeatAgeMs', 'worker_started', 'worker_finished'] as $expected) {
    if (!str_contains($worker, $expected)) throw new RuntimeException("Worker не диагностирует остановку сервиса: {$expected}");
}
foreach (['SERVICE_HEARTBEAT_INTERVAL_MS', 'serviceHeartbeat', 'tracker_event', 'call_capture_started', 'call_capture_finished', 'service_timeout', 'task_removed'] as $expected) {
    if (!str_contains($service, $expected)) throw new RuntimeException("Сервис не пишет этап диагностики: {$expected}");
}
if (!str_contains($repository, 'StabilityDiagnostics.snapshot(appContext, pendingCalls)') || !str_contains($callDao, 'suspend fun getPendingCount(): Int')) {
    throw new RuntimeException('Диагностический снимок и размер очереди не отправляются на сервер');
}
if (!str_contains($analytics, 'screenWidthDp < 380') || !str_contains($analytics, 'isFillViewport = true') ||
    !str_contains($aboutLayout, 'android:ellipsize="end"')) {
    throw new RuntimeException('Верхние панели не адаптированы для экранов шириной около 360dp');
}

echo "android_stability_test: OK\n";
