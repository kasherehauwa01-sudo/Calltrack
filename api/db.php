<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $config = getDbConfig();
    $pdo = getPdo();
    $stmt = $pdo->query('SELECT DATABASE() AS db_name, USER() AS mysql_user, VERSION() AS version');
    $info = $stmt->fetch() ?: [];
    sendJson([
        'status' => 'success',
        'message' => 'MariaDB connection OK',
        'config' => [
            'host' => $config['host'],
            'db' => $config['db'],
            'user' => $config['user'],
            'env_override' => [
                'CALLTRACK_DB_HOST' => getenv('CALLTRACK_DB_HOST') !== false && trim((string)getenv('CALLTRACK_DB_HOST')) !== '',
                'CALLTRACK_DB_NAME' => getenv('CALLTRACK_DB_NAME') !== false && trim((string)getenv('CALLTRACK_DB_NAME')) !== '',
                'CALLTRACK_DB_USER' => getenv('CALLTRACK_DB_USER') !== false && trim((string)getenv('CALLTRACK_DB_USER')) !== '',
                'CALLTRACK_DB_PASS' => getenv('CALLTRACK_DB_PASS') !== false && trim((string)getenv('CALLTRACK_DB_PASS')) !== '',
            ],
        ],
        'server' => $info,
    ]);
} catch (Throwable $e) {
    $config = function_exists('getDbConfig') ? getDbConfig() : [];
    sendJson([
        'status' => 'error',
        'message' => $e->getMessage(),
        'config' => [
            'host' => $config['host'] ?? null,
            'db' => $config['db'] ?? null,
            'user' => $config['user'] ?? null,
            'env_override' => [
                'CALLTRACK_DB_HOST' => getenv('CALLTRACK_DB_HOST') !== false && trim((string)getenv('CALLTRACK_DB_HOST')) !== '',
                'CALLTRACK_DB_NAME' => getenv('CALLTRACK_DB_NAME') !== false && trim((string)getenv('CALLTRACK_DB_NAME')) !== '',
                'CALLTRACK_DB_USER' => getenv('CALLTRACK_DB_USER') !== false && trim((string)getenv('CALLTRACK_DB_USER')) !== '',
                'CALLTRACK_DB_PASS' => getenv('CALLTRACK_DB_PASS') !== false && trim((string)getenv('CALLTRACK_DB_PASS')) !== '',
            ],
        ],
    ], 500);
}
