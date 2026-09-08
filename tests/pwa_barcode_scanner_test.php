<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/analizmop/index.html');
$manifest = json_decode((string)file_get_contents($root . '/analizmop/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
$worker = (string)file_get_contents($root . '/analizmop/service-worker.js');
$api = (string)file_get_contents($root . '/api/product_by_ean.php');

if (($manifest['display'] ?? '') !== 'standalone' || ($manifest['start_url'] ?? '') !== './index.html') {
    throw new RuntimeException('Мобильная версия не настроена как устанавливаемое PWA');
}
foreach (['rel="manifest"', 'barcodeScannerBtn', 'scanner-fab', "new BarcodeDetector({formats:['ean_13']})", "facingMode:{ideal:'environment'}", 'toggleBarcodeTorch', 'capabilities?.torch', 'playBarcodeSignal(false)', 'playBarcodeSignal(true)', 'renderProductCard(payload.data)', "serviceWorker.register('./service-worker.js')"] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("В PWA отсутствует функция сканера: {$required}");
}
foreach (['APP_SHELL', "event.request.mode==='navigate'", 'caches.match(event.request)'] as $required) {
    if (!str_contains($worker, $required)) throw new RuntimeException("Service Worker не поддерживает оболочку PWA: {$required}");
}
foreach (['products_cache.json', 'Некорректная контрольная сумма EAN-13', "'data'=>null"] as $required) {
    if (!str_contains($api, $required)) throw new RuntimeException("API товара не поддерживает EAN-13: {$required}");
}

echo "pwa_barcode_scanner_test: OK\n";
