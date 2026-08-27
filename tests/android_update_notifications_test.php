<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/service/CallTrackingService.kt');
$layout = (string)file_get_contents($root . '/app/src/main/res/layout/fragment_notifications.xml');
$viewModel = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/notifications/NotificationViewModel.kt');
$filter = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/notifications/NotificationFilter.kt');
$directory = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/data/repository/ClientDirectory.kt');

foreach (['repo.sendUserTelemetry()', 'BACKGROUND_COMMAND_POLL_INTERVAL_MS = 5 * 60 * 1000L', 'while (isActive)'] as $expected) {
    if (!str_contains($service, $expected)) throw new RuntimeException("Сервис не получает команды в фоне: {$expected}");
}
foreach (['chipAll', 'android:text="Все"', 'chipAppUpdates', 'android:text="Обновления"'] as $expected) {
    if (!str_contains($layout, $expected)) throw new RuntimeException("Нет фильтра уведомлений: {$expected}");
}
if (!str_contains($viewModel, 'MutableStateFlow(NotificationFilter.ALL)') ||
    !str_contains($viewModel, 'it.type == NotificationType.APP_UPDATE') ||
    !str_contains($filter, 'APP_UPDATE')) {
    throw new RuntimeException('Обновления скрыты фильтром уведомлений');
}
foreach (['test_clients.php', 'clientCards[normalized]', 'CARD_CACHE_TTL_MS'] as $expected) {
    if (!str_contains($directory, $expected)) throw new RuntimeException("Карточка не загружается из кэша Clients: {$expected}");
}

echo "android_update_notifications_test: OK\n";
