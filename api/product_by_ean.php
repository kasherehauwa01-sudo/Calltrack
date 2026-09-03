<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$ean13 = preg_replace('/\D+/', '', (string)($_GET['ean13'] ?? ''));
if (strlen($ean13) !== 13) sendJson(['status'=>'error', 'message'=>'Передайте штрихкод EAN-13'], 400);
$sum = 0;
for ($index = 0; $index < 12; $index++) $sum += (int)$ean13[$index] * ($index % 2 === 0 ? 1 : 3);
if ((10 - $sum % 10) % 10 !== (int)$ean13[12]) sendJson(['status'=>'error', 'message'=>'Некорректная контрольная сумма EAN-13'], 400);

$storage = trim((string)(getenv('CALLTRACK_STORAGE_DIR') ?: ''));
$catalogFile = ($storage !== '' ? rtrim($storage, '/') : dirname(__DIR__) . '/storage') . '/products_cache.json';
$products = is_file($catalogFile) ? json_decode((string)file_get_contents($catalogFile), true) : [];
if (!is_array($products)) sendJson(['status'=>'error', 'message'=>'Локальный кэш товаров повреждён'], 500);
foreach ($products as $product) {
    if (!is_array($product)) continue;
    $barcode = preg_replace('/\D+/', '', (string)($product['ean13'] ?? $product['barcode'] ?? ''));
    if ($barcode !== $ean13) continue;
    $name = trim((string)($product['name'] ?? $product['Наименование'] ?? ''));
    sendJson(['status'=>'success', 'data'=>['ean13'=>$ean13, 'name'=>$name !== '' ? $name : $ean13, 'fields'=>is_array($product['fields'] ?? null) ? $product['fields'] : $product]]);
}
sendJson(['status'=>'success', 'data'=>null]);
