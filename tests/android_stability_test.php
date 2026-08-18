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
if (!str_contains($service, 'runCatching { tracker.start() }') || !str_contains($logger, 'PRUNE_INTERVAL_MS')) {
    throw new RuntimeException('Не добавлена защита сервиса или экономное обслуживание журнала');
}
if (!str_contains($analytics, 'screenWidthDp < 380') || !str_contains($analytics, 'isFillViewport = true') ||
    !str_contains($aboutLayout, 'android:ellipsize="end"')) {
    throw new RuntimeException('Верхние панели не адаптированы для экранов шириной около 360dp');
}

echo "android_stability_test: OK\n";
