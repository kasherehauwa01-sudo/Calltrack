<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$path = dirname(__DIR__) . '/updates/update.json';
if (!is_file($path)) {
    sendJson(['status' => 'error', 'message' => 'update.json not found'], 404);
}

$content = file_get_contents($path);
$data = json_decode($content === false ? '' : $content, true);
if (!is_array($data)) {
    sendJson(['status' => 'error', 'message' => 'update.json is invalid'], 500);
}

$data['status'] = 'ok';
sendJson($data);
