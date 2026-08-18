<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/api/config.php');

foreach (['updates/update.json', "['versionCode']", "['filename']", '$metadataCode === $versionCode'] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Отсутствует резервное разрешение APK из update.json: {$expected}");
    }
}

$fallbackPosition = strpos($source, '$metadataPath');
$notFoundPosition = strpos($source, "'APK update not found'", $fallbackPosition ?: 0);
if ($fallbackPosition === false || $notFoundPosition === false || $fallbackPosition > $notFoundPosition) {
    throw new RuntimeException('Ответ 404 формируется до попытки прочитать update.json');
}

echo "update_download_fallback_test: OK\n";
