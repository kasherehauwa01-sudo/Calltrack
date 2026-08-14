<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function normalizeClientPhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    return strlen($digits) >= 10 ? substr($digits, -10) : '';
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

function splitClientPhones(string|array $value): array
{
    if (is_array($value)) {
        $phones = [];
        foreach ($value as $item) foreach (splitClientPhones((string)$item) as $phone) $phones[$phone] = $phone;
        return array_values($phones);
    }
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
        $id = clientRawValue($row, ['id', 'client_id', 'ID']);
        $client = ['name'=>$name, 'phones'=>$phones, 'fields'=>filledClientFields($row)];
        if ((string)$id !== '') $client['id'] = (string)$id;
        $result[] = $client;
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
    return clientsCacheDirectory() . '/clients.json';
}

function clientsPhoneIndexCacheFile(): string
{
    return clientsCacheDirectory() . '/phone_index.json';
}

function clientsSqliteFile(): string
{
    return clientsCacheDirectory() . '/clients.sqlite';
}

function clientsSyncStateFile(): string
{
    return clientsCacheDirectory() . '/sync_state.json';
}

function clientsOpenSqlite(?string $path = null): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Для delta-обновления Clients требуется расширение pdo_sqlite');
    }
    $pdo = new PDO('sqlite:' . ($path ?? clientsSqliteFile()), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS clients (client_id TEXT PRIMARY KEY, name TEXT NOT NULL, payload_json TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS client_phones (client_id TEXT NOT NULL, normalized_phone TEXT NOT NULL, shard_code TEXT NOT NULL, PRIMARY KEY(client_id, normalized_phone), FOREIGN KEY(client_id) REFERENCES clients(client_id) ON DELETE CASCADE)');
    $columns = $pdo->query('PRAGMA table_info(client_phones)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('shard_code', $columns, true)) {
        $pdo->exec("ALTER TABLE client_phones ADD COLUMN shard_code TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_phones_phone ON client_phones(normalized_phone)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_phones_shard ON client_phones(shard_code)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    return $pdo;
}

function clientsSqliteUpsert(PDO $pdo, array $client, string $clientId): void
{
    $client['id'] = $clientId;
    $json = json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare('INSERT INTO clients(client_id,name,payload_json) VALUES(?,?,?) ON CONFLICT(client_id) DO UPDATE SET name=excluded.name,payload_json=excluded.payload_json');
    $statement->execute([$clientId, (string)($client['name'] ?? ''), $json]);
    $pdo->prepare('DELETE FROM client_phones WHERE client_id=?')->execute([$clientId]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO client_phones(client_id,normalized_phone,shard_code) VALUES(?,?,?)');
    foreach (($client['phones'] ?? []) as $phone) if ((string)$phone !== '') $insert->execute([$clientId, (string)$phone, substr(sha1((string)$phone), 0, 2)]);
}

function clientsSqliteRows(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT payload_json FROM clients ORDER BY client_id') as $row) {
        $client = json_decode((string)$row['payload_json'], true);
        if (is_array($client)) $rows[] = $client;
    }
    return $rows;
}

function clientsProjectStorageDirectory(): string
{
    $configured = trim((string)(getenv('CALLTRACK_STORAGE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__) . '/storage';
}

function clientsEnsureDirectory(string $directory): string
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        $error = error_get_last()['message'] ?? 'неизвестная системная ошибка';
        throw new RuntimeException("Не удалось создать каталог Clients {$directory}: {$error}");
    }
    if (!is_readable($directory) || !is_writable($directory)) {
        throw new RuntimeException("Каталог Clients недоступен для чтения или записи: {$directory}");
    }
    return $directory;
}

function clientsCacheDirectory(): string
{
    return clientsEnsureDirectory(clientsProjectStorageDirectory() . '/cache/clients');
}

function clientsCacheShardsDirectory(): string
{
    return clientsEnsureDirectory(clientsCacheDirectory() . '/shards');
}

function clientsCacheTempDirectory(): string
{
    return clientsEnsureDirectory(clientsCacheDirectory() . '/temp');
}

function clientsLookupShardFile(string $normalizedPhone): string
{
    $shard = substr(sha1($normalizedPhone), 0, 2);
    return clientsCacheShardsDirectory() . '/' . $shard . '.json';
}

function clientsLookupReadyFile(): string
{
    return clientsCacheDirectory() . '/shards.ready';
}

function clientsFileError(string $operation, string $path): RuntimeException
{
    $error = error_get_last()['message'] ?? 'неизвестная системная ошибка';
    return new RuntimeException("{$operation}: {$path}; системная ошибка: {$error}");
}

function cleanupStaleClientsCacheFiles(int $olderThanSeconds = 21600): int
{
    $removed = 0;
    $threshold = time() - $olderThanSeconds;
    foreach ([clientsCacheDirectory(), clientsCacheShardsDirectory(), clientsCacheTempDirectory()] as $directory) {
        foreach (glob($directory . '/*.new.*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) !== false && filemtime($file) < $threshold && unlink($file)) $removed++;
        }
    }
    foreach (glob(clientsCacheTempDirectory() . '/shard_rows_*.ndjson') ?: [] as $file) {
        if (is_file($file) && filemtime($file) !== false && filemtime($file) < $threshold && unlink($file)) $removed++;
    }
    return $removed;
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
    if (is_file(clientsSqliteFile())) {
        $pdo = clientsOpenSqlite();
        $statement = $pdo->prepare('SELECT c.payload_json FROM client_phones p JOIN clients c ON c.client_id=p.client_id WHERE p.normalized_phone=? ORDER BY c.client_id');
        $statement->execute([$normalizedPhone]);
        $matches = [];
        foreach ($statement as $row) {
            $client = json_decode((string)$row['payload_json'], true);
            if (is_array($client)) $matches[] = ['phone'=>'+7'.$normalizedPhone, 'name'=>(string)($client['name'] ?? ''), 'fields'=>is_array($client['fields'] ?? null) ? $client['fields'] : []];
        }
        return $matches;
    }
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
    $shardPattern = clientsCacheShardsDirectory() . '/*.json';
    foreach (glob($shardPattern) ?: [] as $oldShard) {
        if (!in_array($oldShard, $activeShards, true)) @unlink($oldShard);
    }
    if (@file_put_contents(clientsLookupReadyFile(), date(DATE_ATOM), LOCK_EX) === false) {
        throw new RuntimeException('Не удалось завершить индекс карточек Clients');
    }
}

function clientsStreamingCacheCreate(): array
{
    cleanupStaleClientsCacheFiles();
    $suffix = '.new.' . getmypid();
    $cache = clientsCacheFile() . $suffix;
    $index = clientsPhoneIndexCacheFile() . $suffix;
    $cacheHandle = @fopen($cache, 'wb');
    $indexHandle = @fopen($index, 'wb');
    if ($cacheHandle === false || $indexHandle === false) {
        if (is_resource($cacheHandle)) fclose($cacheHandle);
        if (is_resource($indexHandle)) fclose($indexHandle);
        @unlink($cache); @unlink($index);
        throw clientsFileError('Не удалось создать временный потоковый кэш Clients', $cacheHandle === false ? $cache : $index);
    }
    fwrite($cacheHandle, '[');
    fwrite($indexHandle, '{');
    $sqlite = clientsSqliteFile() . $suffix;
    $sqlitePdo = clientsOpenSqlite($sqlite);
    $sqlitePdo->beginTransaction();
    return ['suffix'=>$suffix, 'cache'=>$cache, 'index'=>$index, 'sqlite'=>$sqlite, 'sqlite_pdo'=>$sqlitePdo, 'cache_handle'=>$cacheHandle,
        'index_handle'=>$indexHandle, 'first_client'=>true, 'first_index'=>true, 'shards'=>[], 'publish_temporary'=>[]];
}

function clientsStreamingCacheAppend(array &$stream, array $clients): int
{
    $written = 0;
    foreach ($clients as $client) {
        $json = json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || fwrite($stream['cache_handle'], ($stream['first_client'] ? '' : ',') . $json) === false) {
            throw clientsFileError('Не удалось записать страницу во временный кэш Clients', $stream['cache']);
        }
        $stream['first_client'] = false;
        $written++;
        $clientId = (string)($client['id'] ?? ($client['fields']['id'] ?? $client['fields']['client_id'] ?? ''));
        if ($clientId === '') throw new RuntimeException('Полный ответ Clients содержит клиента без id');
        clientsSqliteUpsert($stream['sqlite_pdo'], $client, $clientId);
        foreach (($client['phones'] ?? []) as $phone) {
            $phone = (string)$phone;
            $indexEntry = json_encode($phone, JSON_UNESCAPED_UNICODE) . ':' . json_encode((string)$client['name'], JSON_UNESCAPED_UNICODE);
            if (fwrite($stream['index_handle'], ($stream['first_index'] ? '' : ',') . $indexEntry) === false) {
                throw clientsFileError('Не удалось записать потоковый индекс телефонов Clients', $stream['index']);
            }
            $stream['first_index'] = false;
            $shard = substr(sha1($phone), 0, 2);
            if (!isset($stream['shards'][$shard])) {
                $path = clientsCacheTempDirectory() . '/shard_rows_' . getmypid() . '_' . $shard . '.ndjson';
                $handle = @fopen($path, 'ab');
                if ($handle === false) throw clientsFileError('Не удалось создать временный shard Clients', $path);
                $stream['shards'][$shard] = ['path'=>$path, 'handle'=>$handle];
            }
            $row = [$phone, ['phone'=>'+7'.$phone, 'name'=>(string)$client['name'],
                'fields'=>is_array($client['fields'] ?? null) ? $client['fields'] : []]];
            if (fwrite($stream['shards'][$shard]['handle'], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
                throw clientsFileError('Не удалось записать временный shard Clients', $stream['shards'][$shard]['path']);
            }
        }
    }
    return $written;
}

function clientsStreamingCacheAbort(array &$stream): void
{
    if (($stream['sqlite_pdo'] ?? null) instanceof PDO && $stream['sqlite_pdo']->inTransaction()) $stream['sqlite_pdo']->rollBack();
    $stream['sqlite_pdo'] = null;
    foreach (['cache_handle', 'index_handle'] as $key) if (is_resource($stream[$key] ?? null)) fclose($stream[$key]);
    foreach ($stream['shards'] ?? [] as $shard) {
        if (is_resource($shard['handle'] ?? null)) fclose($shard['handle']);
        @unlink($shard['path']);
    }
    foreach ($stream['publish_temporary'] ?? [] as $file) @unlink($file);
    @unlink($stream['cache'] ?? ''); @unlink($stream['index'] ?? ''); @unlink($stream['sqlite'] ?? '');
}

function clientsStreamingCachePublish(array &$stream): void
{
    $stream['sqlite_pdo']->commit();
    $stream['sqlite_pdo'] = null;
    fwrite($stream['cache_handle'], ']'); fclose($stream['cache_handle']); $stream['cache_handle'] = null;
    fwrite($stream['index_handle'], '}'); fclose($stream['index_handle']); $stream['index_handle'] = null;
    $temporaryTargets = [];
    foreach ($stream['shards'] as $shardCode=>$shard) {
        fclose($shard['handle']); $stream['shards'][$shardCode]['handle'] = null;
        $rows = [];
        $input = @fopen($shard['path'], 'rb');
        if ($input === false) throw clientsFileError('Не удалось прочитать временный shard Clients', $shard['path']);
        while (($line = fgets($input)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row[0], $row[1])) $rows[(string)$row[0]][] = $row[1];
        }
        fclose($input); @unlink($shard['path']);
        $target = clientsCacheShardsDirectory() . '/' . $shardCode . '.json';
        $temporary = $target . $stream['suffix'];
        $stream['publish_temporary'][] = $temporary;
        if (file_put_contents($temporary, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw clientsFileError('Не удалось подготовить потоковый shard Clients', $temporary);
        }
        unset($rows);
        $temporaryTargets[] = ['temporary'=>$temporary, 'target'=>$target];
    }
    @unlink(clientsLookupReadyFile());
    if (!@rename($stream['cache'], clientsCacheFile())) {
        throw clientsFileError('Не удалось атомарно опубликовать основной кэш Clients', clientsCacheFile());
    }
    if (!@rename($stream['index'], clientsPhoneIndexCacheFile())) {
        throw clientsFileError('Не удалось атомарно опубликовать индекс телефонов Clients', clientsPhoneIndexCacheFile());
    }
    if (!@rename($stream['sqlite'], clientsSqliteFile())) {
        throw clientsFileError('Не удалось опубликовать SQLite-кэш Clients', clientsSqliteFile());
    }
    foreach ($temporaryTargets as $file) {
        if (!rename($file['temporary'], $file['target'])) throw clientsFileError('Не удалось опубликовать shard Clients', $file['target']);
        $key = array_search($file['temporary'], $stream['publish_temporary'], true);
        if ($key !== false) unset($stream['publish_temporary'][$key]);
    }
    $active = array_column($temporaryTargets, 'target');
    $pattern = clientsCacheShardsDirectory() . '/*.json';
    foreach (glob($pattern) ?: [] as $old) if (!in_array($old, $active, true)) @unlink($old);
    if (@file_put_contents(clientsLookupReadyFile(), date(DATE_ATOM), LOCK_EX) === false) {
        throw clientsFileError('Не удалось завершить потоковый индекс Clients', clientsLookupReadyFile());
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

    if (is_file(clientsSqliteFile())) return clientsSqliteRows(clientsOpenSqlite());
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
