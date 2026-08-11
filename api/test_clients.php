<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';

function clientsCurlRequest(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На сервере Calltrack не установлено расширение PHP cURL');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Не удалось инициализировать запрос к Clients');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    return [
        'http_code'=>$httpCode,
        'body'=>$body === false ? '' : (string)$body,
        'curl_error'=>$curlError,
    ];
}

function fetchClientsApiRows(string $url): array
{
    // URL передаётся в cURL без добавления путей и без fallback-переходов.
    $http = clientsCurlRequest($url);
    if ($http['curl_error'] !== '') {
        throw new RuntimeException('Ошибка соединения с Clients: ' . $http['curl_error']);
    }
    if ($http['http_code'] !== 200) {
        throw new RuntimeException('Clients API вернул HTTP ' . $http['http_code']);
    }
    if (trim($http['body']) === '') {
        throw new RuntimeException('Clients API вернул пустой ответ');
    }

    $payload = json_decode($http['body'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Clients API вернул некорректный JSON');
    }
    if (($payload['status'] ?? '') === 'error') {
        throw new RuntimeException('Clients API сообщил об ошибке: ' . (string)($payload['message'] ?? 'без описания'));
    }
    $rows = $payload['data'] ?? $payload['clients'] ?? $payload['items'] ?? $payload;
    if (!is_array($rows)) {
        throw new RuntimeException('В ответе Clients API отсутствует массив клиентов');
    }
    return normalizeClientsPayload(array_values($rows));
}

function testClientPhoneAgainstApi(string $rawPhone, string $url): array
{
    $normalized = normalizeClientPhone($rawPhone);
    $displayPhone = strlen($normalized) === 10 ? '+7' . $normalized : $rawPhone;
    if (strlen($normalized) !== 10) {
        return [
            'found'=>false,
            'phone'=>$displayPhone,
            'normalized'=>$normalized,
            'matches'=>[],
            'matches_count'=>0,
            'reason'=>'После удаления форматирования номер должен содержать 10 цифр.',
        ];
    }

    $clients = fetchClientsApiRows($url);
    $matches = findClientsByPhone($clients, $normalized);
    return [
        'found'=>(bool)$matches,
        'phone'=>$displayPhone,
        'normalized'=>$normalized,
        'matches'=>$matches,
        'matches_count'=>count($matches),
        'reason'=>$matches ? '' : sprintf(
            'Номер %s не найден в колонке «Телефоны». Проверено клиентов: %d.',
            $displayPhone,
            count($clients)
        ),
    ];
}

function testClientPhone(string $rawPhone): array
{
    $url = trim((string)(getenv('CALLTRACK_CLIENTS_API_URL') ?: CLIENTS_API_URL));
    if ($url === '') {
        throw new RuntimeException('Не настроен CLIENTS_API_URL');
    }
    return testClientPhoneAgainstApi($rawPhone, $url);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        sendJson(['status'=>'error', 'message'=>'Разрешён только метод GET'], 405);
    }
    $phone = trim((string)($_GET['phone'] ?? ''));
    if ($phone === '') {
        sendJson(['status'=>'error', 'message'=>'Передайте номер телефона'], 400);
    }
    sendJson(['status'=>'success', 'data'=>testClientPhone($phone)]);
} catch (Throwable $e) {
    error_log('Clients API test failed: ' . $e->getMessage());
    sendJson(['status'=>'error', 'message'=>$e->getMessage()], 502);
}
