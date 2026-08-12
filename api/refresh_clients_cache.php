<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';
require_once __DIR__ . '/test_clients.php';

if (PHP_SAPI !== 'cli') {
    sendJson(['status'=>'error', 'message'=>'Обновление кэша доступно только из CLI'], 403);
}

$lockPath = sys_get_temp_dir() . '/calltrack_clients_refresh.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

try {
    writeClientsRefreshStatus(['status'=>'running']);
    $url = trim((string)(getenv('CALLTRACK_CLIENTS_API_URL') ?: CLIENTS_API_URL));
    $clients = fetchClientsApiRows($url);
    writeClientsCache($clients);
    writeClientsRefreshStatus(['status'=>'success', 'clients'=>count($clients)]);
} catch (Throwable $e) {
    writeClientsRefreshStatus(['status'=>'error', 'message'=>$e->getMessage()]);
    error_log('Clients cache refresh failed: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
