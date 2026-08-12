<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';
require_once __DIR__ . '/test_clients.php';

const CLIENTS_REFRESH_TIMEZONE = 'Europe/Moscow';

function clientsRefreshStorageDir(): string
{
    $configured = trim((string)(getenv('CALLTRACK_STORAGE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__) . '/storage';
}

function ensureClientsRefreshStorage(): string
{
    $directory = clientsRefreshStorageDir();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать служебный каталог CallTrack');
    }
    return $directory;
}

function clientsRefreshLockFile(): string
{
    return ensureClientsRefreshStorage() . '/clients_cache_refresh.lock';
}

function clientsRefreshLogFile(): string
{
    return ensureClientsRefreshStorage() . '/logs/clients_cache_refresh.log';
}

function clientsCronMarkerFile(): string
{
    $configured = trim((string)(getenv('CALLTRACK_CLIENTS_CRON_MARKER') ?: ''));
    return $configured !== '' ? $configured : '/etc/calltrack/clients-cache-cron.installed';
}

function clientsRefreshNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(CLIENTS_REFRESH_TIMEZONE));
}

function formatClientsRefreshTime(DateTimeImmutable $time): string
{
    return $time->setTimezone(new DateTimeZone(CLIENTS_REFRESH_TIMEZONE))->format('d.m.Y H:i:s');
}

function clientsNextRefresh(DateTimeImmutable $now = null): DateTimeImmutable
{
    $now = ($now ?? clientsRefreshNow())->setTimezone(new DateTimeZone(CLIENTS_REFRESH_TIMEZONE));
    $next = $now->setTime(4, 0, 0);
    return $now < $next ? $next : $next->modify('+1 day');
}

function appendClientsRefreshLog(string $message): void
{
    $path = clientsRefreshLogFile();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return;
    // Ограничиваем файл примерно пятью мегабайтами без внешней зависимости от logrotate.
    if (is_file($path) && filesize($path) > 5 * 1024 * 1024) {
        @rename($path, $path . '.1');
    }
    @file_put_contents($path, '[' . formatClientsRefreshTime(clientsRefreshNow()) . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function clientsRefreshStatusPayload(array $status): array
{
    $now = clientsRefreshNow();
    $configured = is_file(clientsCronMarkerFile());
    $lastCronAt = isset($status['last_cron_started_at']) ? strtotime((string)$status['last_cron_started_at']) : false;
    $stale = $lastCronAt === false || $lastCronAt < $now->getTimestamp() - 36 * 3600;
    $cronState = !$configured ? 'no_data' : ($stale ? 'configured' : 'working');
    if ($configured && $lastCronAt !== false && $stale) $cronState = 'overdue';

    return $status + [
        'cron_state'=>$cronState,
        'cron_configured'=>$configured,
        'schedule'=>'Ежедневно в 04:00 МСК',
        'timezone'=>CLIENTS_REFRESH_TIMEZONE,
        'next_run_at'=>formatClientsRefreshTime(clientsNextRefresh($now)),
    ];
}

function runClientsCacheRefresh(string $source): array
{
    $source = in_array($source, ['cron', 'manual', 'update_calltrack.sh'], true) ? $source : 'update_calltrack.sh';
    $lock = fopen(clientsRefreshLockFile(), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Обновление кэша Clients уже выполняется');
    }

    $started = clientsRefreshNow();
    $previous = readClientsRefreshStatus();
    $running = [
        'status'=>'running',
        'success'=>false,
        'source'=>$source,
        'started_at'=>$started->format(DATE_ATOM),
        'finished_at'=>null,
        'clients'=>null,
        'error'=>null,
    ];
    if ($source === 'cron') $running['last_cron_started_at'] = $started->format(DATE_ATOM);
    elseif (isset($previous['last_cron_started_at'])) $running['last_cron_started_at'] = $previous['last_cron_started_at'];
    writeClientsRefreshStatus($running);
    appendClientsRefreshLog("Начато обновление кэша Clients (источник: {$source})");

    try {
        $url = trim((string)(getenv('CALLTRACK_CLIENTS_API_URL') ?: CLIENTS_API_URL));
        $clients = fetchClientsApiRows($url);
        if (!$clients) throw new RuntimeException('Clients API не вернул ни одной корректной записи');
        appendClientsRefreshLog('Получено клиентов: ' . count($clients));
        writeClientsCache($clients);
        $finished = clientsRefreshNow();
        $result = $running + [];
        $result['status'] = 'success';
        $result['success'] = true;
        $result['finished_at'] = $finished->format(DATE_ATOM);
        $result['clients'] = count($clients);
        writeClientsRefreshStatus($result);
        appendClientsRefreshLog('Кэш успешно обновлен');
        return clientsRefreshStatusPayload($result);
    } catch (Throwable $error) {
        $failed = $running;
        $failed['status'] = 'error';
        $failed['finished_at'] = clientsRefreshNow()->format(DATE_ATOM);
        $failed['error'] = $error->getMessage();
        writeClientsRefreshStatus($failed);
        appendClientsRefreshLog('ERROR: ' . $error->getMessage());
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
