<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/analizmop/index.html');
$manifest = json_decode((string)file_get_contents($root . '/analizmop/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
$worker = (string)file_get_contents($root . '/analizmop/service-worker.js');

if (($manifest['display'] ?? '') !== 'standalone' || ($manifest['start_url'] ?? '') !== './index.html') {
    throw new RuntimeException('Мобильная версия не настроена как устанавливаемое PWA');
}
foreach (['rel="manifest"', "serviceWorker.register('./service-worker.js')"] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("В PWA отсутствует обязательная настройка: {$required}");
}
foreach (['barcodeScannerBtn', 'scanner-fab', 'barcodeScannerModal', 'BarcodeDetector', 'Сканировать штрихкод'] as $removed) {
    if (str_contains($html, $removed)) throw new RuntimeException("Иконка или функция сканера не удалена: {$removed}");
}
foreach (['APP_SHELL', "event.request.mode==='navigate'", 'caches.match(event.request)'] as $required) {
    if (!str_contains($worker, $required)) throw new RuntimeException("Service Worker не поддерживает оболочку PWA: {$required}");
}

echo "pwa_barcode_scanner_test: OK\n";
