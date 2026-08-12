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

function clientsNextRefresh(?DateTimeImmutable $now = null): DateTimeImmutable
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
    $source = in_array($source, ['cron', 'manual'], true) ? $source : 'manual';
    $lock = @fopen(clientsRefreshLockFile(), 'c');
    if ($lock === false) {
        throw new RuntimeException('Не удалось открыть файл блокировки обновления кэша Clients');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
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
        $url = trim((string)(getenv('CALLTRACK_CLIENTS_PAGINATED_API_URL') ?: CLIENTS_PAGINATED_API_URL));
        $pageSize = max(100, min(2000, (int)(getenv('CALLTRACK_CLIENTS_REFRESH_PAGE_SIZE') ?: CLIENTS_REFRESH_PAGE_SIZE)));
        $stream = clientsStreamingCacheCreate();
        $clientsCount = 0;
        $sourceRecordsCount = 0;
        $page = 1;
        $processedPages = 0;
        $sourceTotal = null;
        $effectivePageSize = $pageSize;
        try {
            do {
                $batch = fetchClientsApiPage($url, $page, $pageSize);
                if ($sourceTotal !== null && $batch['total'] !== $sourceTotal) {
                    throw new RuntimeException("Количество Clients изменилось во время обновления: {$sourceTotal} → {$batch['total']}");
                }
                $sourceTotal = $batch['total'];
                $effectivePageSize = $batch['page_size'];
                $sourceRecordsCount += $batch['source_count'];
                $clientsCount += clientsStreamingCacheAppend($stream, $batch['clients']);
                $processedPages++;
                unset($batch['clients']);
                $hasMore = (bool)$batch['has_more'];
                unset($batch);
                gc_collect_cycles();
                $page++;
            } while ($hasMore);
            if ($sourceTotal === null || $sourceRecordsCount !== $sourceTotal) {
                throw new RuntimeException("Пагинация Clients завершилась частично: обработано {$sourceRecordsCount} из {$sourceTotal}");
            }
            if ($clientsCount === 0) throw new RuntimeException('Clients API не вернул ни одной корректной записи');
            appendClientsRefreshLog("Всего записей в Clients API: {$sourceTotal}");
            appendClientsRefreshLog("Обработано страниц: {$processedPages}");
            appendClientsRefreshLog("Обработано исходных записей: {$sourceRecordsCount}");
            appendClientsRefreshLog("Записано клиентов: {$clientsCount}");
            clientsStreamingCachePublish($stream);
        } catch (Throwable $streamError) {
            clientsStreamingCacheAbort($stream);
            throw $streamError;
        }
        $finished = clientsRefreshNow();
        $peakMemory = memory_get_peak_usage(true);
        $result = $running + [];
        $result['status'] = 'success';
        $result['success'] = true;
        $result['finished_at'] = $finished->format(DATE_ATOM);
        $result['clients'] = $clientsCount;
        $result['peak_memory_bytes'] = $peakMemory;
        $result['page_size'] = $effectivePageSize;
        $result['processed_pages'] = $processedPages;
        $result['source_total'] = $sourceTotal;
        $result['source_records_processed'] = $sourceRecordsCount;
        $cacheSize = filesize(clientsCacheFile());
        $result['cache_size_bytes'] = $cacheSize === false ? null : $cacheSize;
        writeClientsRefreshStatus($result);
        appendClientsRefreshLog('Peak memory: ' . round($peakMemory / 1048576, 1) . ' MB');
        appendClientsRefreshLog('Размер нового кэша: ' . round((int)$cacheSize / 1048576, 1) . ' MB');
        appendClientsRefreshLog('Кэш успешно опубликован');
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

function clientsRefreshIsLocked(): bool
{
    $lock = @fopen(clientsRefreshLockFile(), 'c');
    if ($lock === false) {
        throw new RuntimeException('Не удалось открыть файл блокировки обновления кэша Clients');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return true;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return false;
}

function startClientsCacheRefreshInBackground(): array
{
    if (!function_exists('exec')) {
        throw new RuntimeException('Фоновый запуск недоступен: функция PHP exec отключена');
    }
    if (clientsRefreshIsLocked()) {
        throw new RuntimeException('Обновление кэша Clients уже выполняется');
    }
    $current = readClientsRefreshStatus();
    $updatedAt = strtotime((string)($current['updated_at'] ?? '')) ?: 0;
    if (($current['status'] ?? '') === 'starting' && $updatedAt > time() - 30) {
        throw new RuntimeException('Обновление кэша Clients уже выполняется');
    }

    $phpBinary = trim((string)(getenv('CALLTRACK_PHP_CLI') ?: '/usr/bin/php'));
    $script = __DIR__ . '/refresh_clients_cache.php';
    if (!is_file($phpBinary) || !is_executable($phpBinary)) {
        throw new RuntimeException('Не найден исполняемый файл PHP CLI для фонового обновления Clients');
    }
    if (!is_file($script)) {
        throw new RuntimeException('Не найден CLI-скрипт обновления кэша Clients');
    }

    $previous = readClientsRefreshStatus();
    $starting = [
        'status'=>'starting',
        'success'=>false,
        'source'=>'manual',
        'started_at'=>clientsRefreshNow()->format(DATE_ATOM),
        'finished_at'=>null,
        'clients'=>null,
        'error'=>null,
    ];
    if (isset($previous['last_cron_started_at'])) $starting['last_cron_started_at'] = $previous['last_cron_started_at'];
    writeClientsRefreshStatus($starting);

    // Все части команды формируются сервером и экранируются; клиент не передаёт пути или аргументы.
    $command = sprintf(
        'nohup %s %s --source=manual </dev/null >/dev/null 2>&1 & echo $!',
        escapeshellarg($phpBinary),
        escapeshellarg($script)
    );
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    $pid = isset($output[0]) && ctype_digit(trim((string)$output[0])) ? (int)trim((string)$output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 0) {
        $starting['status'] = 'error';
        $starting['finished_at'] = clientsRefreshNow()->format(DATE_ATOM);
        $starting['error'] = 'Не удалось создать фоновый процесс обновления кэша Clients';
        writeClientsRefreshStatus($starting);
        appendClientsRefreshLog('ERROR: ' . $starting['error']);
        throw new RuntimeException($starting['error']);
    }
    appendClientsRefreshLog("Создан фоновый процесс ручного обновления Clients, PID: {$pid}");
    $starting['pid'] = $pid;
    return clientsRefreshStatusPayload($starting);
}
