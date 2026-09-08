<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_sync.php';

if (PHP_SAPI !== 'cli') {
    sendJson(['status'=>'error', 'message'=>'Синхронизация доступна только из CLI'], 403);
}

try {
    $lockPath = sys_get_temp_dir() . '/calltrack-email-sync.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, "Синхронизация Email уже выполняется\n");
        exit(0);
    }
    // Нулевой лимит означает полный проход папок Sent: после выполнения в БД
    // присутствуют все доступные исходящие письма каждого активного ящика.
    $result = syncEmailMailboxes(getPdo(), null, 0);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['errors'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка синхронизации Email: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
