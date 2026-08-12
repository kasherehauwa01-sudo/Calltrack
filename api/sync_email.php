<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_sync.php';

if (PHP_SAPI !== 'cli') {
    sendJson(['status'=>'error', 'message'=>'Синхронизация доступна только из CLI'], 403);
}

try {
    $result = syncEmailMailboxes(getPdo());
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['errors'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка синхронизации Email: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
