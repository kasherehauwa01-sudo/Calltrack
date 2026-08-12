<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/clients_cache_refresh.php';

function refreshExpect(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$timezone = new DateTimeZone('Europe/Moscow');
refreshExpect(
    clientsNextRefresh(new DateTimeImmutable('2026-08-12 03:00:00', $timezone))->format('Y-m-d H:i') === '2026-08-12 04:00',
    'До 04:00 следующим должен быть сегодняшний запуск'
);
refreshExpect(
    clientsNextRefresh(new DateTimeImmutable('2026-08-12 17:00:00', $timezone))->format('Y-m-d H:i') === '2026-08-13 04:00',
    'После 04:00 следующим должен быть завтрашний запуск'
);

putenv('CALLTRACK_STORAGE_DIR=/proc/calltrack-storage-is-not-writable');
try {
    clientsRefreshIsLocked();
    refreshExpect(false, 'Ошибка открытия lock-файла не была обнаружена');
} catch (RuntimeException $error) {
    refreshExpect(
        $error->getMessage() === 'Не удалось создать служебный каталог CallTrack'
            || $error->getMessage() === 'Не удалось открыть файл блокировки обновления кэша Clients',
        'Ошибка открытия lock-файла имеет неверный текст'
    );
}

$storage = sys_get_temp_dir() . '/calltrack_refresh_test_' . getmypid();
mkdir($storage, 0777, true);
putenv('CALLTRACK_STORAGE_DIR=' . $storage);
$lock = fopen($storage . '/clients_cache_refresh.lock', 'c');
refreshExpect(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'Не удалось подготовить занятую блокировку');
refreshExpect(clientsRefreshIsLocked(), 'Занятый flock должен определяться как выполняющееся обновление');
flock($lock, LOCK_UN);
fclose($lock);
refreshExpect(!clientsRefreshIsLocked(), 'Освобождённый flock не должен считаться занятым');

@unlink($storage . '/clients_cache_refresh.lock');
@rmdir($storage);

putenv('CALLTRACK_CLIENTS_API_URL=http://streaming-cache-test.invalid/api');
$stream = clientsStreamingCacheCreate();
$expected = 2500;
for ($offset = 0; $offset < $expected; $offset += 500) {
    $batch = [];
    for ($index = $offset; $index < min($expected, $offset + 500); $index++) {
        $phone = str_pad((string)$index, 10, '0', STR_PAD_LEFT);
        $batch[] = ['name'=>'Клиент ' . $index, 'phones'=>[$phone], 'fields'=>['Телефон'=>$phone]];
    }
    clientsStreamingCacheAppend($stream, $batch);
    unset($batch);
}
clientsStreamingCachePublish($stream);
$cache = readClientsCache();
refreshExpect(count($cache) === $expected, 'Потоковый кэш потерял записи при пакетной записи');
refreshExpect($cache[2499]['name'] === 'Клиент 2499', 'Последняя запись потокового кэша повреждена');
@unlink(clientsCacheFile());
@unlink(clientsPhoneIndexCacheFile());
@unlink(clientsLookupReadyFile());
foreach (glob(sys_get_temp_dir() . '/calltrack_clients_lookup_' . sha1(implode('|', clientsApiUrls())) . '_*.json') ?: [] as $file) @unlink($file);
echo "clients_cache_refresh_test: OK\n";
