<?php
declare(strict_types=1);

require_once __DIR__ . '/kotlin_source.php';

$source = readKotlinSource(dirname(__DIR__) . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$layout = (string)file_get_contents(dirname(__DIR__) . '/app/src/main/res/layout/dialog_update_progress.xml');
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
if (!str_contains($source, 'archiveVersion != expectedVersion.toLong()')) {
    throw new RuntimeException('Удалена защита от несовпадения версии update.json и APK');
}
foreach ([
    'private val updateHttpClient = OkHttpClient()',
    'private val updateDownloadHttpClient = updateHttpClient.newBuilder()',
    '.connectTimeout(30, TimeUnit.SECONDS)',
    '.readTimeout(5, TimeUnit.MINUTES)',
    '.writeTimeout(30, TimeUnit.SECONDS)',
    '.callTimeout(6, TimeUnit.MINUTES)',
    'private const val APK_DOWNLOAD_MAX_ATTEMPTS = 2',
    'private const val APK_DOWNLOAD_RETRY_DELAY_MS = 2_000L',
    'error !is IOException',
    'copiedBytes != contentLength',
    'File(cacheDir, "$APK_FILE_NAME.part").delete()',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Отсутствует требование надёжной загрузки APK: {$expected}");
    }
}
if (!str_contains($source, 'updateDownloadHttpClient.newCall(request)')) {
    throw new RuntimeException('APK загружается не выделенным HTTP-клиентом');
}
foreach (['@string/update_install_instruction', '@drawable/update_install_step_1', '@drawable/update_install_step_2'] as $expected) {
    if (!str_contains($layout, $expected)) {
        throw new RuntimeException("На экране загрузки отсутствует инструкция: {$expected}");
    }
}
if (!str_contains($source, 'R.layout.dialog_update_progress')) {
    throw new RuntimeException('Экран загрузки не использует разметку с инструкцией');
}

echo "android_update_flow_test: OK\n";
