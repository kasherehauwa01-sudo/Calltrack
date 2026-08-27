<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/api/admin_install_update.php');
$config = (string)file_get_contents($root . '/api/config.php');
$report = (string)file_get_contents($root . '/api/user_report.php');
$repository = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/data/repository/CallRepository.kt');
$main = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$html = (string)file_get_contents($root . '/analizmop/index.html');

foreach (['app_version_code IS NOT NULL', 'app_version_code < :version_code', "command = 'install_update'", "status = 'pending'", 'NOT EXISTS'] as $expected) {
    if (!str_contains($endpoint, $expected)) throw new RuntimeException("Серверная очередь не содержит: {$expected}");
}
foreach (['app_version_code BIGINT NULL', 'target_version_code BIGINT NULL', 'target_version_name VARCHAR(50) NULL'] as $expected) {
    if (!str_contains($config, $expected)) throw new RuntimeException("Схема не содержит: {$expected}");
}
if (!str_contains($report, 'target_version_code, target_version_name')) {
    throw new RuntimeException('Целевая версия не возвращается приложению');
}
foreach (['put("app_version_code", BuildConfig.VERSION_CODE)', '"install_update"', '"Обновите приложение"', 'NotificationType.APP_UPDATE'] as $expected) {
    if (!str_contains($repository, $expected)) throw new RuntimeException("Android-команда не содержит: {$expected}");
}
if (!str_contains($main, 'NotificationTargets.APP_UPDATE') || !str_contains($main, 'AboutActivity::class.java')) {
    throw new RuntimeException('Нажатие уведомления не открывает экран обновления');
}
foreach (['installLatestUpdateForAllBtn', 'Установить всем пользователям последнее обновление', 'installLatestUpdateForAll'] as $expected) {
    if (!str_contains($html, $expected)) throw new RuntimeException("Админ-панель не содержит: {$expected}");
}

echo "install_update_for_all_test: OK\n";
