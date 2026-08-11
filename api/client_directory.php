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

function clientsCardApiUrl(string $phone): string
{
    $configured = getenv('CALLTRACK_CLIENTS_CARD_API_URL') ?: (defined('CLIENTS_CARD_API_URL') ? CLIENTS_CARD_API_URL : '');
    if (trim((string)$configured) === '') return '';
    $separator = str_contains((string)$configured, '?') ? '&' : '?';
    return trim((string)$configured) . $separator . 'phone=' . rawurlencode($phone);
}

function clientsRequestHeaders(string $url, string $userAgent): string
{
    $host = parse_url($url, PHP_URL_HOST);
    $hostHeader = in_array($host, ['127.0.0.1', 'localhost'], true) ? "Host: kvasmix.ru\r\n" : '';
    return $hostHeader . "Accept: application/json\r\nUser-Agent: {$userAgent}\r\nConnection: close\r\n";
}

function clientsCurlRequest(string $url, string $userAgent, ?int $timeout=null): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('На сервере не установлено расширение PHP cURL');
    $token = getenv('CALLTRACK_CLIENTS_API_TOKEN') ?: (defined('CLIENTS_API_TOKEN') ? CLIENTS_API_TOKEN : '');
    $port = (int)(getenv('CALLTRACK_CLIENTS_API_PORT') ?: (defined('CLIENTS_API_PORT') ? CLIENTS_API_PORT : 0));
    $connectTimeout = (int)(getenv('CALLTRACK_CLIENTS_API_CONNECT_TIMEOUT') ?: (defined('CLIENTS_API_CONNECT_TIMEOUT') ? CLIENTS_API_CONNECT_TIMEOUT : 3));
    $requestTimeout = $timeout ?? (int)(getenv('CALLTRACK_CLIENTS_API_TIMEOUT') ?: (defined('CLIENTS_API_TIMEOUT') ? CLIENTS_API_TIMEOUT : 8));
    $headers = ['Accept: application/json', "User-Agent: {$userAgent}", 'Connection: close'];
    if (trim((string)$token) !== '') $headers[] = 'Authorization: Bearer ' . trim((string)$token);
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>max(1, $connectTimeout),
        CURLOPT_TIMEOUT=>max(1, $requestTimeout),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ];
    if ($port > 0) $options[CURLOPT_PORT] = $port;
    $resolveLocal = getenv('CALLTRACK_CLIENTS_API_RESOLVE_LOCAL');
    $resolveLocal = $resolveLocal === false ? (defined('CLIENTS_API_RESOLVE_LOCAL') && CLIENTS_API_RESOLVE_LOCAL) : filter_var($resolveLocal, FILTER_VALIDATE_BOOL);
    $host = (string)parse_url($url, PHP_URL_HOST);
    if ($resolveLocal && $host === 'kvasmix.ru') $options[CURLOPT_RESOLVE] = ["kvasmix.ru:{$port}:127.0.0.1"];
    curl_setopt_array($curl, $options);
    $startedAt = microtime(true);
    $body = curl_exec($curl);
    $diagnostic = [
        'url'=>$url,
        'effective_url'=>(string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
        'port'=>$port,
        'http_code'=>(int)curl_getinfo($curl, CURLINFO_HTTP_CODE),
        'curl_errno'=>curl_errno($curl),
        'curl_error'=>curl_error($curl),
        'dns_ms'=>round((float)curl_getinfo($curl, CURLINFO_NAMELOOKUP_TIME)*1000, 1),
        'connect_ms'=>round((float)curl_getinfo($curl, CURLINFO_CONNECT_TIME)*1000, 1),
        'tls_ms'=>round((float)curl_getinfo($curl, CURLINFO_APPCONNECT_TIME)*1000, 1),
        'total_ms'=>round((microtime(true)-$startedAt)*1000, 1),
        'token_configured'=>trim((string)$token) !== '',
        'body'=>$body === false ? '' : (string)$body,
    ];
    curl_close($curl);
    error_log('Clients cURL: ' . json_encode(array_diff_key($diagnostic, ['body'=>true]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($diagnostic['curl_errno'] !== 0) {
        throw new RuntimeException(sprintf(
            'Clients cURL error: url=%s, port=%d, errno=%d, error=%s, HTTP=%d',
            $url, $port, $diagnostic['curl_errno'], $diagnostic['curl_error'], $diagnostic['http_code']
        ));
    }
    return $diagnostic;
}

function readClientsRawCache(string $cacheFile): ?array
{
    if (!is_file($cacheFile) || filemtime($cacheFile) === false || filemtime($cacheFile) <= time() - 300) return null;
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($cached) && isset($cached['rows'], $cached['source_total']) ? $cached : null;
}

function readClientsStaleRawCache(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) return null;
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
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $lock = false;
            if (($stale = readClientsStaleRawCache($cacheFile)) !== null) {
                error_log(sprintf('Clients timing: concurrent refresh, stale cache used, rows=%d', $stale['source_total']));
                return $stale;
            }
            throw new RuntimeException('Справочник clients обновляется, повторите запрос');
        }
        if (($cached = readClientsRawCache($cacheFile)) !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            error_log(sprintf('Clients timing: raw cache hit after lock, total=%.0f ms, rows=%d', (microtime(true)-$startedAt)*1000, $cached['source_total']));
            return $cached;
        }
    }
    $lastReason = 'не удалось подключиться к API clients';
    foreach (clientsApiUrls() as $url) {
        $requestStartedAt = microtime(true);
        error_log("Clients timing: CallTrack -> Clients start, url={$url}");
        try {
            $http = clientsCurlRequest($url, 'CallTrack/clients-test');
        } catch (Throwable $e) {
            $lastReason = $e->getMessage();
            error_log($lastReason);
            continue;
        }
        $body = $http['body'];
        $receivedAt = microtime(true);
        $statusCode = $http['http_code'];
        if ($statusCode >= 400) {$lastReason="API clients вернул HTTP {$statusCode}; response={$body}";continue;}
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

function fetchClientCardsDirect(string $normalizedPhone): ?array
{
    $url = clientsCardApiUrl($normalizedPhone);
    if ($url === '') return null;
    $startedAt = microtime(true);
    error_log('Clients timing: CallTrack -> Clients card start');
    $http = clientsCurlRequest($url, 'CallTrack/client-card', 5);
    $body = $http['body'];
    $statusCode = $http['http_code'];
    error_log(sprintf('Clients timing: direct card response=%d, total=%.0f ms', $statusCode, (microtime(true)-$startedAt)*1000));
    if ($statusCode === 404) return null;
    if ($statusCode >= 400) throw new RuntimeException("API карточки clients вернул HTTP {$statusCode}; response={$body}");
    $payload = json_decode($body, true);
    if (!is_array($payload)) throw new RuntimeException('API карточки clients вернул некорректный JSON');
    if (($payload['status'] ?? 'success') === 'error') throw new RuntimeException((string)($payload['message'] ?? 'API карточки clients сообщил об ошибке'));
    $cards = $payload['data'] ?? [];
    if (!is_array($cards)) throw new RuntimeException('API карточки clients не вернул массив data');
    return array_values(array_filter($cards, 'is_array'));
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
    $cards = fetchClientCardsDirect($normalized);
    if ($cards === null) {
        $response = fetchClientsApiRows();
        $cards = findClientCardsInRows($response['rows'], $normalized);
        error_log('Clients timing: direct card endpoint unavailable, directory fallback used');
    }
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
