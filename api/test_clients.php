<?php
declare(strict_types=1);

require_once __DIR__ . '/client_directory.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    sendJson(['status'=>'error', 'message'=>'Для теста API используйте POST'], 405);
}

$data = readJsonBody();
$phone = valueOrNull($data, 'phone');
if (!is_scalar($phone)) {
    sendJson(['status'=>'error', 'message'=>'номер телефона не введён'], 422);
}

$normalizedPhone = normalizeClientPhone((string)$phone);
if ($normalizedPhone === '') {
    sendJson([
        'status'=>'error',
        'message'=>'введённое значение содержит меньше 10 цифр',
    ], 422);
}

try {
    sendJson([
        'status'=>'success',
        'data'=>testClientPhone((string)$phone),
    ]);
} catch (Throwable $e) {
    error_log('Clients API test error: ' . $e->getMessage());
    sendJson([
        'status'=>'error',
        'message'=>'не удалось выполнить запрос: ' . $e->getMessage(),
    ], 502);
}
