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

function splitClientPhones(string|array $value): array
{
    $phones = [];
    foreach ((array)$value as $part) {
        if (!is_scalar($part)) continue;
        preg_match_all('/(?<!\d)(?:\+?7|8)?(?:[\s().-]*\d){10}(?!\d)/u', (string)$part, $matches);
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
    $nameValue = clientValue($row, ['Наименование', 'наименование', 'name', 'client_name', 'client']);
    $name = is_scalar($nameValue) ? trim((string)$nameValue) : '';
    $phones = splitClientPhones(clientValue($row, ['Телефоны', 'телефоны', 'phones', 'phone_numbers', 'phone']));
    return $name === '' || !$phones ? null : ['name'=>$name, 'phones'=>$phones];
}

function normalizeClientsPayload(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        $client = normalizeClientRow($row);
        if ($client !== null) $result[] = $client;
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

function clientsApiStatusCode(array $headers): int
{
    $statusCode = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) $statusCode = (int)$matches[1];
    }
    return $statusCode;
}

function clientsRawCacheFile(): string
{
    return sys_get_temp_dir() . '/calltrack_clients_raw_' . sha1(implode('|', clientsApiUrls())) . '.json';
}

function readClientsRawCache(string $cacheFile): ?array
{
    if (!is_file($cacheFile) || filemtime($cacheFile) === false || filemtime($cacheFile) <= time() - 300) return null;
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($cached) && isset($cached['rows'], $cached['source_total']) ? $cached : null;
}

function fetchClientsApiRows(bool $useCache=true): array
{
    $startedAt = microtime(true);
    $cacheFile = clientsRawCacheFile();
    if ($useCache && ($cached = readClientsRawCache($cacheFile)) !== null) {
        error_log(sprintf('Clients timing: raw cache hit, total=%.0f ms, rows=%d', (microtime(true)-$startedAt)*1000, $cached['source_total']));
        return $cached;
    }
    $lock = $useCache ? fopen($cacheFile . '.lock', 'c') : false;
    if ($lock !== false) {
        flock($lock, LOCK_EX);
        if (($cached = readClientsRawCache($cacheFile)) !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            error_log(sprintf('Clients timing: raw cache hit after lock, total=%.0f ms, rows=%d', (microtime(true)-$startedAt)*1000, $cached['source_total']));
            return $cached;
        }
    }
    $context = stream_context_create(['http'=>[
        // Ответ должен успеть вернуться раньше стандартного 10-секундного таймаута OkHttp.
        'timeout'=>8,
        'ignore_errors'=>true,
        'protocol_version'=>1.1,
        // Закрываем соединение после ответа: PHP stream не должен ждать keep-alive,
        // если upstream не прислал корректный Content-Length.
        'header'=>"Accept: application/json\r\nUser-Agent: CallTrack/clients-test\r\nConnection: close\r\n",
    ]]);
    $lastReason = 'не удалось подключиться к API clients';
    foreach (clientsApiUrls() as $url) {
        $requestStartedAt = microtime(true);
        error_log("Clients timing: CallTrack -> Clients start, url={$url}");
        $body = @file_get_contents($url, false, $context);
        $receivedAt = microtime(true);
        $headers = $http_response_header ?? [];
        $statusCode = clientsApiStatusCode($headers);
        if ($body === false) {$lastReason='не удалось установить соединение с API clients';error_log(sprintf('Clients timing: request failed after %.0f ms', ($receivedAt-$requestStartedAt)*1000));continue;}
        if ($statusCode >= 400) {$lastReason="API clients вернул HTTP {$statusCode}";continue;}
        if (trim($body) === '') {$lastReason='API clients вернул пустой ответ';continue;}
        $payload = json_decode($body, true);
        $parsedAt = microtime(true);
        if (!is_array($payload)) {$lastReason='API clients вернул некорректный JSON';continue;}
        if (($payload['status'] ?? 'success') === 'error') {$lastReason='API clients сообщил об ошибке';continue;}
        $rows = $payload['data'] ?? $payload['clients'] ?? $payload;
        if (!is_array($rows)) {$lastReason='в ответе API clients нет массива data';continue;}
        $result = ['rows'=>array_values($rows), 'source_total'=>count($rows)];
        if ($useCache) {
            $tempFile = $cacheFile . '.tmp.' . getmypid();
            file_put_contents($tempFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            rename($tempFile, $cacheFile);
        }
        if ($lock !== false) {flock($lock, LOCK_UN);fclose($lock);$lock=false;}
        error_log(sprintf(
            'Clients timing: response=%.0f ms, json=%.0f ms, total=%.0f ms, rows=%d',
            ($receivedAt-$requestStartedAt)*1000,
            ($parsedAt-$receivedAt)*1000,
            (microtime(true)-$startedAt)*1000,
            $result['source_total']
        ));
        return $result;
    }
    if ($lock !== false) {flock($lock, LOCK_UN);fclose($lock);}
    throw new RuntimeException($lastReason);
}

function clientsRowsFromApi(): array
{
    try {
        $response = fetchClientsApiRows();
        return normalizeClientsPayload($response['rows']);
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
        if (in_array($normalizedPhone, $client['phones'], true)) {
            // Не останавливаемся на первом результате: один телефон может быть
            // указан у нескольких клиентов проекта clients.
            $matches[] = ['phone'=>'+7' . $normalizedPhone, 'name'=>$client['name']];
        }
    }
    return $matches;
}

function testClientPhoneAgainstApi(string $normalizedPhone): array
{
    $response = fetchClientsApiRows(false);
    $matches = [];
    $normalizedTotal = 0;
    foreach ($response['rows'] as $row) {
        $client = normalizeClientRow($row);
        if ($client === null) continue;
        $normalizedTotal++;
        if (!in_array($normalizedPhone, $client['phones'], true)) continue;
        $key = $normalizedPhone . '|' . $client['name'];
        $matches[$key] = ['phone'=>'+7' . $normalizedPhone, 'name'=>$client['name']];
    }
    return [
        'matches'=>array_values($matches),
        'source_total'=>(int)$response['source_total'],
        'normalized_total'=>$normalizedTotal,
    ];
}

function clientCardFieldValue(mixed $value): string
{
    if (is_bool($value)) return $value ? 'Да' : 'Нет';
    if (is_scalar($value)) return trim((string)$value);
    if (!is_array($value)) return '';
    $parts = [];
    array_walk_recursive($value, static function (mixed $item) use (&$parts): void {
        if (is_bool($item)) $parts[] = $item ? 'Да' : 'Нет';
        elseif (is_scalar($item) && trim((string)$item) !== '') $parts[] = trim((string)$item);
    });
    return implode(', ', array_values(array_unique($parts)));
}

function findClientCardsInRows(array $rows, string $normalizedPhone): array
{
    $cards = [];
    foreach ($rows as $row) {
        $client = normalizeClientRow($row);
        if ($client === null || !in_array($normalizedPhone, $client['phones'], true)) continue;
        $fields = [];
        foreach ($row as $label=>$value) {
            $displayValue = clientCardFieldValue($value);
            if ($displayValue !== '') $fields[(string)$label] = $displayValue;
        }
        $cards[] = ['name'=>$client['name'], 'fields'=>$fields];
    }
    return $cards;
}

function loadClientCards(string $rawPhone): array
{
    $startedAt = microtime(true);
    $normalized = normalizeClientPhone($rawPhone);
    if ($normalized === '') throw new InvalidArgumentException('Номер должен содержать не менее 10 цифр');
    $response = fetchClientsApiRows();
    $cards = findClientCardsInRows($response['rows'], $normalized);
    error_log(sprintf('Clients timing: card lookup=%.0f ms, matches=%d', (microtime(true)-$startedAt)*1000, count($cards)));
    return $cards;
}

function testClientPhone(string $rawPhone): array
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

    // Тест намеренно обходит локальную таблицу и файловый кэш: кнопка должна
    // проверять фактический ответ API проекта clients в момент нажатия.
    $testResult = testClientPhoneAgainstApi($normalized);
    if ($testResult['normalized_total'] === 0) {
        return [
            'found'=>false,
            'phone'=>$displayPhone,
            'normalized'=>$normalized,
            'matches'=>[],
            'matches_count'=>0,
            'reason'=>'Проект clients не вернул ни одной записи с заполненными колонками «Наименование» и «Телефоны».',
        ];
    }

    $matches = $testResult['matches'];
    return [
        'found'=>(bool)$matches,
        'phone'=>$displayPhone,
        'normalized'=>$normalized,
        'matches'=>$matches,
        'matches_count'=>count($matches),
        'reason'=>$matches ? '' : sprintf(
            'Номер %s не найден в колонке «Телефоны». Проверено клиентов: %d.',
            $displayPhone,
            $testResult['normalized_total']
        ),
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (($_GET['card'] ?? '') === '1') {
            $requestStartedAt = microtime(true);
            $cards = loadClientCards(trim((string)($_GET['phone'] ?? '')));
            header('Server-Timing: calltrack;dur=' . round((microtime(true)-$requestStartedAt)*1000, 1));
            sendJson(['status'=>'success', 'data'=>$cards, 'total'=>count($cards)]);
        }
        if (array_key_exists('phone', $_GET)) {
            sendJson(['status'=>'success', 'data'=>testClientPhone(trim((string)$_GET['phone']))]);
        }
        $pdo = getPdo();
        $clients = loadClientsDirectory($pdo);
        sendJson(['status'=>'success', 'data'=>$clients, 'total'=>count($clients)]);
    } catch (Throwable $e) {
        error_log('Clients directory error: ' . $e->getMessage());
        sendJson(['status'=>'error', 'message'=>'Не удалось загрузить справочник проекта clients'], 502);
    }
}
