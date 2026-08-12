<?php
declare(strict_types=1);

require_once __DIR__ . '/clients_cache_refresh.php';

if (PHP_SAPI !== 'cli') {
    sendJson(['status'=>'error', 'message'=>'Обновление кэша доступно только из CLI'], 403);
}

try {
    $source = 'manual';
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (strpos($argument, '--source=') === 0) $source = substr($argument, 9);
    }
    $result = runClientsCacheRefresh($source);
    fwrite(STDOUT, json_encode(['status'=>'success', 'data'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    error_log('Clients cache refresh failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
