<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function normalizeClientPhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
}

function clientValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) return trim((string)$row[$key]);
    }
    return '';
}

function splitClientPhones(string $value): array
{
    preg_match_all('/(?:\+?\d[\d\s().-]{7,}\d)/u', $value, $matches);
    $phones = [];
    foreach ($matches[0] ?? [] as $phone) {
        $normalized = normalizeClientPhone($phone);
        if ($normalized !== '') $phones[$normalized] = $normalized;
    }
    return array_values($phones);
}

function normalizeClientsPayload(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $name = clientValue($row, ['Наименование', 'наименование', 'name', 'client_name', 'client']);
        $rawPhones = clientValue($row, ['Телефоны', 'телефоны', 'phones', 'phone_numbers', 'phone']);
        $phones = splitClientPhones($rawPhones);
        if ($name === '' || !$phones) continue;
        $result[] = ['name'=>$name, 'phones'=>$phones];
    }
    return $result;
}

function clientsRowsFromDatabase(PDO $pdo): array
{
    $exists = $pdo->query("SHOW TABLES LIKE 'clients'")->fetchColumn();
    if (!$exists) return [];
    return normalizeClientsPayload($pdo->query('SELECT * FROM clients')->fetchAll());
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

function clientsRowsFromApi(): array
{
    $context = stream_context_create(['http'=>['timeout'=>10, 'ignore_errors'=>true, 'header'=>"Accept: application/json\r\n"]]);
    foreach (clientsApiUrls() as $url) {
        $body = @file_get_contents($url, false, $context);
        if ($body === false || trim($body) === '') continue;
        $payload = json_decode($body, true);
        if (!is_array($payload)) continue;
        $rows = $payload['data'] ?? $payload['clients'] ?? $payload;
        if (!is_array($rows)) continue;
        $clients = normalizeClientsPayload(array_values($rows));
        if ($clients) return $clients;
    }
    return [];
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

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $clients = loadClientsDirectory(getPdo());
        sendJson(['status'=>'success', 'data'=>$clients, 'total'=>count($clients)]);
    } catch (Throwable $e) {
        error_log('Clients directory error: ' . $e->getMessage());
        sendJson(['status'=>'error', 'message'=>'Не удалось загрузить справочник проекта clients'], 502);
    }
}
