<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
foreach (['Проверка актуальности', 'Загрузка новой версии', 'Установка новой версии'] as $status) {
    if (!str_contains($source, $status)) throw new RuntimeException("Отсутствует статус прогресса: {$status}");
}
if (str_contains($source, 'updateCheckHandled')) {
    throw new RuntimeException('Повторная ручная проверка обновлений всё ещё заблокирована');
}
if (!str_contains($source, 'intent.removeExtra(EXTRA_RUN_UPDATE_CHECK)')) {
    throw new RuntimeException('Команда ручной проверки не помечается обработанной в Intent');
}
if (!str_contains($source, 'onProgress(copiedBytes, contentLength)')) {
    throw new RuntimeException('Прогресс загрузки APK не передаётся в интерфейс');
}

echo "android_update_flow_test: OK\n";
