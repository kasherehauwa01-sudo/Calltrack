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

function clientRawValue(array $row, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) return $row[$key];
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

function clientFieldIsFilled($value): bool
{
    if ($value === null) return false;
    if (is_string($value)) return trim($value) !== '';
    if (is_array($value)) {
        foreach ($value as $item) if (clientFieldIsFilled($item)) return true;
        return false;
    }
    return true;
}

function filledClientFields(array $row): array
{
    $fields = [];
    foreach ($row as $key=>$value) {
        if (!clientFieldIsFilled($value)) continue;
        $fields[(string)$key] = $value;
    }
    return $fields;
}

function normalizeClientsPayload(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $name = clientValue($row, ['Наименование', 'наименование', 'name', 'client_name', 'client']);
        $rawPhones = clientRawValue($row, ['Телефоны', 'телефоны', 'phones', 'phone_numbers', 'phone']);
        $phones = [];
        foreach (is_array($rawPhones) ? $rawPhones : [$rawPhones] as $rawPhone) {
            foreach (splitClientPhones((string)$rawPhone) as $phone) $phones[$phone] = $phone;
        }
        $phones = array_values($phones);
        if ($name === '' || !$phones) continue;
        // Исходные заполненные поля сохраняются для карточки клиента в тесте API.
        $result[] = ['name'=>$name, 'phones'=>$phones, 'fields'=>filledClientFields($row)];
    }
    return $result;
}

function clientsRowsFromDatabase(PDO $pdo): array
{
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'clients'")->fetchColumn();
        if (!$exists) return [];
        return normalizeClientsPayload($pdo->query('SELECT * FROM clients')->fetchAll());
    } catch (Throwable $e) {
        error_log('Local clients table is unavailable: ' . $e->getMessage());
        return [];
    }
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

function clientsCacheFile(): string
{
    // v2 хранит не только name/phones, но и заполненные поля карточки клиента.
    return sys_get_temp_dir() . '/calltrack_clients_v2_' . sha1(implode('|', clientsApiUrls())) . '.json';
}

function clientsPhoneIndexCacheFile(): string
{
    return sys_get_temp_dir() . '/calltrack_clients_phone_index_' . sha1(implode('|', clientsApiUrls())) . '.json';
}

function clientsLookupShardFile(string $normalizedPhone): string
{
    $shard = substr(sha1($normalizedPhone), 0, 2);
    return sys_get_temp_dir() . '/calltrack_clients_lookup_' . sha1(implode('|', clientsApiUrls())) . '_' . $shard . '.json';
}

function clientsLookupReadyFile(): string
{
    return sys_get_temp_dir() . '/calltrack_clients_lookup_' . sha1(implode('|', clientsApiUrls())) . '.ready';
}

function clientsRefreshStatusFile(): string
{
    $storage = trim((string)(getenv('CALLTRACK_STORAGE_DIR') ?: ''));
    $directory = $storage !== '' ? rtrim($storage, '/') : dirname(__DIR__) . '/storage';
    if (!is_dir($directory)) @mkdir($directory, 0775, true);
    return $directory . '/clients_cache_refresh.status.json';
}

function readClientsRefreshStatus(): array
{
    $statusFile = clientsRefreshStatusFile();
    if (!is_file($statusFile)) return [];
    $status = json_decode((string)file_get_contents($statusFile), true);
    return is_array($status) ? $status : [];
}

function writeClientsRefreshStatus(array $status): void
{
    $status['updated_at'] = date(DATE_ATOM);
    $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать статус обновления Clients');
    $target = clientsRefreshStatusFile();
    $temporary = $target . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось сохранить статус обновления Clients');
    }
}

function readClientsCache(): array
{
    $cacheFile = clientsCacheFile();
    if (!is_file($cacheFile)) return [];
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($cached) ? $cached : [];
}

function readClientsPhoneIndexCache(): array
{
    $cacheFile = clientsPhoneIndexCacheFile();
    if (!is_file($cacheFile)) return [];
    $index = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($index) ? $index : [];
}

function readClientMatchesCache(string $normalizedPhone): ?array
{
    $cacheFile = clientsLookupShardFile($normalizedPhone);
    if (!is_file($cacheFile)) return is_file(clientsLookupReadyFile()) ? [] : null;
    $shard = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($shard)) return null;
    $matches = $shard[$normalizedPhone] ?? [];
    return is_array($matches) ? $matches : [];
}

function writeClientsCache(array $clients): void
{
    $json = json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $indexJson = json_encode(buildClientPhoneIndex($clients), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || $indexJson === false) {
        throw new RuntimeException('Не удалось подготовить локальный кэш Clients');
    }

    $suffix = '.new.' . getmypid();
    $cacheTemporary = clientsCacheFile() . $suffix;
    $indexTemporary = clientsPhoneIndexCacheFile() . $suffix;
    if (@file_put_contents($cacheTemporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить локальный кэш Clients');
    }
    if (@file_put_contents($indexTemporary, $indexJson, LOCK_EX) === false) {
        @unlink($cacheTemporary);
        throw new RuntimeException('Не удалось сохранить индекс телефонов Clients');
    }


    // Полные карточки разбиваются на небольшие файлы. Тест одного номера
    // читает только один shard и не декодирует весь многомегабайтный справочник.
    $shards = [];
    foreach ($clients as $client) {
        if (!is_array($client)) continue;
        foreach (($client['phones'] ?? []) as $phone) {
            $phone = (string)$phone;
            if ($phone === '') continue;
            $shards[substr(sha1($phone), 0, 2)][$phone][] = [
                'phone'=>'+7' . $phone,
                'name'=>(string)($client['name'] ?? ''),
                'fields'=>is_array($client['fields'] ?? null) ? $client['fields'] : [],
            ];
        }
    }
    $temporaryShards = [];
    foreach ($shards as $shardRows) {
        $firstPhone = (string)array_key_first($shardRows);
        $shardJson = json_encode($shardRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $target = clientsLookupShardFile($firstPhone);
        $temporary = $target . $suffix;
        if ($shardJson === false || @file_put_contents($temporary, $shardJson, LOCK_EX) === false) {
            @unlink($cacheTemporary);
            @unlink($indexTemporary);
            foreach ($temporaryShards as $file) @unlink($file['temporary']);
            throw new RuntimeException('Не удалось сохранить индекс карточек Clients');
        }
        $temporaryShards[] = ['temporary'=>$temporary, 'target'=>$target];
    }

    // Действующий набор не трогаем, пока полностью не подготовлены все новые файлы.
    @unlink(clientsLookupReadyFile());
    if (!@rename($cacheTemporary, clientsCacheFile()) || !@rename($indexTemporary, clientsPhoneIndexCacheFile())) {
        foreach ($temporaryShards as $file) @unlink($file['temporary']);
        throw new RuntimeException('Не удалось атомарно заменить кэш Clients');
    }
    foreach ($temporaryShards as $file) {
        if (!@rename($file['temporary'], $file['target'])) throw new RuntimeException('Не удалось заменить индекс карточек Clients');
    }
    $activeShards = array_column($temporaryShards, 'target');
    $shardPattern = sys_get_temp_dir() . '/calltrack_clients_lookup_' . sha1(implode('|', clientsApiUrls())) . '_*.json';
    foreach (glob($shardPattern) ?: [] as $oldShard) {
        if (!in_array($oldShard, $activeShards, true)) @unlink($oldShard);
    }
    if (@file_put_contents(clientsLookupReadyFile(), date(DATE_ATOM), LOCK_EX) === false) {
        throw new RuntimeException('Не удалось завершить индекс карточек Clients');
    }
}

function clientsRowsFromApi(): array
{
    $context = stream_context_create(['http'=>[
        'timeout'=>3,
        'ignore_errors'=>true,
        'follow_location'=>0,
        'header'=>"Accept: application/json\r\nConnection: close\r\n",
    ]]);
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

function loadClientsDirectory(PDO $pdo, bool $allowRemote = true): array
{
    $clients = clientsRowsFromDatabase($pdo);
    if ($clients) return $clients;

    $cacheFile = clientsCacheFile();
    if (is_file($cacheFile) && filemtime($cacheFile) !== false && filemtime($cacheFile) > time() - 300) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    if (!$allowRemote) {
        // Основные API Calltrack не должны ждать внешний сервис. Свежий кэш
        // обновляется отдельным client_directory.php или тестом интеграции.
        // Просроченный кэш допустим для необязательного обогащения названий.
        if (is_file($cacheFile)) {
            $stale = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($stale)) return $stale;
        }
        return [];
    }
    $clients = clientsRowsFromApi();
    if ($clients) {
        writeClientsCache($clients);
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
        if (in_array($normalizedPhone, $client['phones'], true)) {
            // Не останавливаемся на первом результате: один телефон может быть
            // указан у нескольких клиентов проекта clients.
            $matches[] = [
                'phone'=>'+7' . $normalizedPhone,
                'name'=>$client['name'],
                'fields'=>is_array($client['fields'] ?? null) ? $client['fields'] : [],
            ];
        }
    }
    return $matches;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $pdo = getPdo();
        $clients = loadClientsDirectory($pdo);
        sendJson(['status'=>'success', 'data'=>$clients, 'total'=>count($clients)]);
    } catch (Throwable $e) {
        error_log('Clients directory error: ' . $e->getMessage());
        sendJson(['status'=>'error', 'message'=>'Не удалось загрузить справочник проекта clients'], 502);
    }
}
