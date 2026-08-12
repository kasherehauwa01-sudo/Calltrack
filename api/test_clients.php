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
    $configuredTimeout = getenv('CALLTRACK_CLIENTS_API_TIMEOUT');
    $timeout = max(15, (int)($configuredTimeout !== false ? $configuredTimeout : CLIENTS_API_TIMEOUT));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_FOLLOWLOCATION=>false,
        // Просим сжатый ответ: реальный справочник Clients может превышать 15 МБ.
        CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>['Accept: application/json', 'Connection: close'],
    ]);
    $body = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $curlError = curl_error($curl);
    curl_close($curl);

    return [
        'http_code'=>$httpCode,
        'effective_url'=>$effectiveUrl,
        'body'=>$body === false ? '' : (string)$body,
        'curl_error'=>$curlError,
        'timeout'=>$timeout,
    ];
}

function fetchClientsApiRows(string $url): array
{
    // URL передаётся в cURL без добавления путей и без fallback-переходов.
    $http = clientsCurlRequest($url);
    if ($http['curl_error'] !== '') {
        throw new RuntimeException(sprintf(
            'Ошибка соединения с Clients после %d сек. (получено %d байт): %s',
            $http['timeout'],
            strlen($http['body']),
            $http['curl_error']
        ));
    }
    if ($http['http_code'] !== 200) {
        throw new RuntimeException(sprintf(
            'Clients API вернул HTTP %d для URL %s',
            $http['http_code'],
            $http['effective_url'] !== '' ? $http['effective_url'] : $url
        ));
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

function testClientPhone(string $rawPhone, array $clients): array
{
    $normalized = normalizeClientPhone($rawPhone);
    $displayPhone = strlen($normalized) === 10 ? '+7' . $normalized : $rawPhone;
    if (strlen($normalized) !== 10) {
        return [
            'found'=>false, 'phone'=>$displayPhone, 'normalized'=>$normalized,
            'matches'=>[], 'matches_count'=>0,
            'reason'=>'После удаления форматирования номер должен содержать 10 цифр.',
        ];
    }
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

function testClientPhoneFromCache(string $rawPhone): ?array
{
    $normalized = normalizeClientPhone($rawPhone);
    $displayPhone = strlen($normalized) === 10 ? '+7' . $normalized : $rawPhone;
    if (strlen($normalized) !== 10) {
        return [
            'found'=>false, 'phone'=>$displayPhone, 'normalized'=>$normalized,
            'matches'=>[], 'matches_count'=>0,
            'reason'=>'После удаления форматирования номер должен содержать 10 цифр.',
        ];
    }
    $matches = readClientMatchesCache($normalized);
    if ($matches === null) return null;
    return [
        'found'=>(bool)$matches,
        'phone'=>$displayPhone,
        'normalized'=>$normalized,
        'matches'=>$matches,
        'matches_count'=>count($matches),
        'reason'=>$matches ? '' : sprintf('Номер %s не найден в колонке «Телефоны».', $displayPhone),
    ];
}

function startClientsCacheRefresh(): bool
{
    if (!function_exists('exec')) return false;
    $script = __DIR__ . '/refresh_clients_cache.php';
    $configuredBinary = trim((string)(getenv('CALLTRACK_PHP_CLI') ?: ''));
    $candidates = array_filter([$configuredBinary, '/usr/bin/php', PHP_BINDIR . '/php']);
    $phpCli = '';
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {$phpCli=$candidate;break;}
    }
    if ($phpCli === '') return false;

    $status = readClientsRefreshStatus();
    $status['status'] = 'starting';
    $status['source'] = 'background_test';
    $status['php_cli'] = $phpCli;
    writeClientsRefreshStatus($status);
    $command = sprintf('%s %s >/dev/null 2>&1 &', escapeshellarg($phpCli), escapeshellarg($script));
    exec($command, $output, $exitCode);
    return $exitCode === 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

try {
    // cURL имеет собственный контролируемый timeout; PHP не должен завершить
    // обработчик раньше, чем закончится загрузка большого справочника.
    @set_time_limit(max(30, (int)CLIENTS_API_TIMEOUT + 10));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        sendJson(['status'=>'error', 'message'=>'Разрешён только метод GET'], 405);
    }
    $phone = trim((string)($_GET['phone'] ?? ''));
    if ($phone === '') {
        sendJson(['status'=>'error', 'message'=>'Передайте номер телефона'], 400);
    }
    // Не читаем полный кэш карточек: на рабочем справочнике его декодирование
    // могло превышать memory_limit PHP и завершать endpoint ответом HTTP 500.
    $cachedResult = testClientPhoneFromCache($phone);
    if ($cachedResult === null) {
        $refreshStatus = readClientsRefreshStatus();
        if (($refreshStatus['status'] ?? '') === 'error') {
            throw new RuntimeException('Фоновая загрузка Clients завершилась ошибкой: ' . ($refreshStatus['message'] ?? 'без описания'));
        }
        $refreshUpdatedAt = strtotime((string)($refreshStatus['updated_at'] ?? '')) ?: 0;
        $refreshRunning = in_array($refreshStatus['status'] ?? '', ['starting','running'], true)
            && $refreshUpdatedAt > time() - CLIENTS_API_TIMEOUT - 30;
        if (!$refreshRunning && !startClientsCacheRefresh()) {
            throw new RuntimeException('Не удалось запустить фоновое обновление Clients: проверьте PHP CLI, exec и CALLTRACK_PHP_CLI');
        }
        sendJson(['status'=>'success', 'data'=>[
            'pending'=>true,
            'reason'=>'Справочник Clients загружается в фоне. Ожидайте завершения.',
            'refresh_status'=>readClientsRefreshStatus(),
        ]], 202);
    }
    sendJson(['status'=>'success', 'data'=>$cachedResult]);
} catch (Throwable $e) {
    error_log('Clients API test failed: ' . $e->getMessage());
    sendJson(['status'=>'error', 'message'=>$e->getMessage()], 502);
}
