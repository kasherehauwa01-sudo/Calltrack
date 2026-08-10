<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function normalizeClientPhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    return strlen($digits) >= 10 ? substr($digits, -10) : '';
}

function clientValue(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) return $row[$key];
    }
    return '';
}

function splitClientPhones(mixed $value): array
{
    $phones = [];
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $item) {
        if (!is_scalar($item)) continue;
        preg_match_all('/(?<!\d)(?:\+?7|8)?(?:[\s().-]*\d){10}(?!\d)/u', (string)$item, $matches);
        foreach ($matches[0] ?? [] as $phone) {
            $normalized = normalizeClientPhone($phone);
            if ($normalized !== '') $phones[$normalized] = $normalized;
        }
    }
    return array_values($phones);
}

function normalizeClientRow(mixed $row): ?array
{
    if (!is_array($row)) return null;
    $rawName = clientValue($row, ['Наименование', 'наименование', 'name', 'client_name', 'client']);
    $name = is_scalar($rawName) ? trim((string)$rawName) : '';
    $rawPhones = clientValue($row, ['Телефоны', 'телефоны', 'phones', 'phone_numbers', 'phone']);
    $phones = splitClientPhones($rawPhones);
    return $name === '' || !$phones ? null : ['name'=>$name, 'phones'=>$phones];
}

function normalizeClientsPayload(array &$rows): array
{
    $result = [];
    foreach ($rows as $key=>$row) {
        // Внешний справочник очень большой: освобождаем исходную строку сразу после чтения.
        unset($rows[$key]);
        $client = normalizeClientRow($row);
        if ($client !== null) $result[] = $client;
    }
    return $result;
}

function clientsRowsFromDatabase(PDO $pdo): array
{
    $exists = $pdo->query("SHOW TABLES LIKE 'clients'")->fetchColumn();
    if (!$exists) return [];
    $rows = $pdo->query('SELECT * FROM clients')->fetchAll();
    return normalizeClientsPayload($rows);
}

function clientsApiUrls(): array
{
    $configured = getenv('CALLTRACK_CLIENTS_API_URL') ?: (defined('CLIENTS_API_URL') ? CLIENTS_API_URL : '');
    if (trim((string)$configured) !== '') return [trim((string)$configured)];
    return [
        'https://kvasmix.ru/vr/clients/api/get_clients.php',
        'https://kvasmix.ru/vr/clients/api/clients.php',
    ];
}

function clientsApiStatusCode(array $headers): int
{
    $statusCode = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) $statusCode = (int)$matches[1];
    }
    return $statusCode;
}

function fetchClientsApiRows(): array
{
    $context = stream_context_create(['http'=>[
        'timeout'=>30,
        'ignore_errors'=>true,
        'header'=>"Accept: application/json\r\nUser-Agent: CallTrack/clients-test\r\n",
    ]]);
    $lastReason = 'не удалось подключиться к API clients';

    foreach (clientsApiUrls() as $url) {
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = clientsApiStatusCode($responseHeaders);
        if ($body === false) {
            $lastReason = 'не удалось установить соединение с API clients';
            continue;
        }
        if ($statusCode >= 400) {
            $lastReason = "API clients вернул HTTP {$statusCode}";
            continue;
        }
        if (trim($body) === '') {
            $lastReason = 'API clients вернул пустой ответ';
            continue;
        }
        $payload = json_decode($body, true);
        unset($body);
        if (!is_array($payload)) {
            $lastReason = 'API clients вернул некорректный JSON';
            continue;
        }
        if (($payload['status'] ?? 'success') === 'error') {
            $lastReason = 'API clients сообщил об ошибке';
            continue;
        }
        $rows = $payload['data'] ?? $payload['clients'] ?? $payload;
        if (!is_array($rows)) {
            $lastReason = 'в ответе API clients нет JSON-массива data';
            continue;
        }
        $sourceTotal = count($rows);
        unset($payload);
        return [
            'rows'=>$rows,
            'source_total'=>$sourceTotal,
        ];
    }
    throw new RuntimeException($lastReason);
}

function fetchClientsDirectoryFromApi(): array
{
    $response = fetchClientsApiRows();
    $sourceTotal = (int)$response['source_total'];
    $rows = $response['rows'];
    unset($response);
    return [
        'clients'=>normalizeClientsPayload($rows),
        'source_total'=>$sourceTotal,
    ];
}

function clientsRowsFromApi(): array
{
    try {
        return fetchClientsDirectoryFromApi()['clients'];
    } catch (Throwable $e) {
        error_log('Clients API error: ' . $e->getMessage());
        return [];
    }
}

function loadClientsDirectory(PDO $pdo): array
{
    $clients = clientsRowsFromDatabase($pdo);
    if ($clients) return $clients;

    $cacheFile = sys_get_temp_dir() . '/calltrack_clients_' . sha1(implode('|', clientsApiUrls())) . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) !== false && filemtime($cacheFile) > time() - 300) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $clients = clientsRowsFromApi();
    if ($clients) {
        @file_put_contents($cacheFile, json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $clients;
    }
    if (is_file($cacheFile)) {
        $stale = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($stale)) return $stale;
    }
    return [];
}

function buildClientPhoneIndex(array $clients): array
{
    $index = [];
    foreach ($clients as $client) {
        foreach ($client['phones'] as $phone) $index[$phone] ??= $client['name'];
    }
    return $index;
}

function findClientsByPhone(array $clients, string $normalizedPhone): array
{
    $matches = [];
    foreach ($clients as $client) {
        if (!is_array($client)) continue;
        $name = trim((string)($client['name'] ?? ''));
        if ($name === '') continue;
        foreach (($client['phones'] ?? []) as $phone) {
            if (normalizeClientPhone((string)$phone) !== $normalizedPhone) continue;
            $key = $normalizedPhone . '|' . $name;
            $matches[$key] = ['phone'=>'+7' . $normalizedPhone, 'name'=>$name];
            break;
        }
    }
    return array_values($matches);
}

function testClientPhoneAgainstApi(string $normalizedPhone): array
{
    $response = fetchClientsApiRows();
    $sourceTotal = (int)$response['source_total'];
    $rows = $response['rows'];
    unset($response);

    $matches = [];
    $normalizedTotal = 0;
    foreach ($rows as $key=>$row) {
        // Для точечного теста не собираем вторую копию всего справочника в памяти.
        unset($rows[$key]);
        $client = normalizeClientRow($row);
        if ($client === null) continue;
        $normalizedTotal++;
        if (!in_array($normalizedPhone, $client['phones'], true)) continue;
        $matchKey = $normalizedPhone . '|' . $client['name'];
        $matches[$matchKey] = ['phone'=>'+7' . $normalizedPhone, 'name'=>$client['name']];
    }

    return [
        'matches'=>array_values($matches),
        'source_total'=>$sourceTotal,
        'normalized_total'=>$normalizedTotal,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $clients = loadClientsDirectory(getPdo());
        sendJson(['status'=>'success', 'data'=>$clients, 'total'=>count($clients)]);
    } catch (Throwable $e) {
        error_log('Clients directory error: ' . $e->getMessage());
        sendJson(['status'=>'error', 'message'=>'Не удалось загрузить справочник проекта clients'], 502);
    }
}
