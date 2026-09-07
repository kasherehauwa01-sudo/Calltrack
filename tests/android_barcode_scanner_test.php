<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gradle = (string)file_get_contents($root . '/app/build.gradle');
$manifest = (string)file_get_contents($root . '/app/src/main/AndroidManifest.xml');
$layout = (string)file_get_contents($root . '/app/src/main/res/layout/activity_main.xml');
$scanner = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/scanner/BarcodeScannerActivity.kt');
$main = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$products = (string)file_get_contents($root . '/api/product_by_ean.php');

foreach (['zxing-android-embedded', 'btnBarcodeScanner', 'ic_barcode_scanner'] as $required) {
    if (!str_contains($gradle . $layout, $required)) throw new RuntimeException("Не добавлена плавающая кнопка сканера: {$required}");
}
foreach (['android.permission.CAMERA', 'BarcodeScannerActivity'] as $required) {
    if (!str_contains($manifest, $required)) throw new RuntimeException("Сканеру не хватает настройки Android: {$required}");
}
foreach (['BarcodeFormat.EAN_13', 'setTorchOn()', 'setTorchOff()', 'BEEP_ENABLED, false'] as $required) {
    if (!str_contains($scanner, $required)) throw new RuntimeException("Окно сканирования настроено неверно: {$required}");
}
foreach (['ToneGenerator.TONE_PROP_ACK', 'ToneGenerator.TONE_PROP_NACK', 'showProductCard(product)', 'ProductDirectory()'] as $required) {
    if (!str_contains($main, $required)) throw new RuntimeException("Результат сканирования не обработан: {$required}");
}
foreach (['products_cache.json', 'Некорректная контрольная сумма EAN-13', "'data'=>null"] as $required) {
    if (!str_contains($products, $required)) throw new RuntimeException("API карточки товара не проверяет EAN-13: {$required}");
}

echo "android_barcode_scanner_test: OK\n";
