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
    $testResult = testClientPhoneAgainstApi($normalizedPhone);
    $matches = $testResult['matches'];
    $displayPhone = '+7' . $normalizedPhone;

    if ($matches) {
        sendJson([
            'status'=>'success',
            'found'=>true,
            'normalized_phone'=>$displayPhone,
            'data'=>$matches,
        ]);
    }

    $sourceTotal = (int)$testResult['source_total'];
    $normalizedTotal = (int)$testResult['normalized_total'];
    if ($sourceTotal === 0) {
        $reason = 'API clients доступен, но вернул пустой справочник';
    } elseif ($normalizedTotal === 0) {
        $reason = 'API clients вернул записи, но в них нет корректных полей «Наименование» и «Телефоны»';
    } else {
        $reason = "API clients доступен; проверено клиентов: {$normalizedTotal}. Совпадение {$displayPhone} в колонке «Телефоны» отсутствует";
    }

    sendJson([
        'status'=>'success',
        'found'=>false,
        'normalized_phone'=>$displayPhone,
        'data'=>[],
        'reason'=>$reason,
    ]);
} catch (Throwable $e) {
    error_log('Clients API test error: ' . $e->getMessage());
    sendJson([
        'status'=>'error',
        'message'=>'не удалось выполнить запрос: ' . $e->getMessage(),
    ], 502);
}
