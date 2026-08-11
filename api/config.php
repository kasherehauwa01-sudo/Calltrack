<?php
// Конфигурация подключения к MySQL/MariaDB на Timeweb.
// Рекомендуется переопределять значения через переменные окружения хостинга,
// а не хранить реальный пароль в репозитории.

declare(strict_types=1);

$externalConfig = '/etc/calltrack/config.local.php';

if (file_exists($externalConfig)) {
    require_once $externalConfig;
} elseif (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} elseif (file_exists(dirname(__DIR__) . '/config.local.php')) {
    require_once dirname(__DIR__) . '/config.local.php';
}
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');       // пример Timeweb: localhost или mysqlXX.timeweb.ru
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'calltrack');       // имя базы данных проекта
}
if (!defined('DB_USER')) {
    define('DB_USER', 'calltrack_user');  // пользователь MariaDB на Timeweb
}
if (!defined('DB_PASS')) {
    define('DB_PASS', 'YOUR_PASSWORD');   // задайте реальный пароль в config.local.php или CALLTRACK_DB_PASS
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}
if (!defined('UPDATE_PUBLIC_BASE')) {
    define('UPDATE_PUBLIC_BASE', 'https://kvasmix.ru/vr/calltrack/updates/');
}
if (!defined('UPDATE_DOWNLOAD_URL')) {
    define('UPDATE_DOWNLOAD_URL', 'https://kvasmix.ru/vr/calltrack/api/update.php?download=1');
}
if (!defined('CLIENTS_API_URL')) {
    // Оба проекта обслуживаются одним Nginx: loopback исключает внешний DNS,
    // TLS и запрет исходящего соединения сервера с собственным публичным адресом.
    define('CLIENTS_API_URL', 'http://127.0.0.1/vr/clients/api/get_clients.php');
}
if (!defined('CLIENTS_CARD_API_URL')) {
    define('CLIENTS_CARD_API_URL', 'http://127.0.0.1/vr/clients/api/client_card.php');
}
if (!defined('CLIENTS_CARD_API_URL')) {
    define('CLIENTS_CARD_API_URL', 'https://kvasmix.ru/vr/clients/api/client_card.php');
}
if (!defined('CLIENTS_CARD_API_URL')) {
    define('CLIENTS_CARD_API_URL', 'https://kvasmix.ru/vr/clients/api/client_card.php');
}
if (!defined('CLIENTS_API_PORT')) define('CLIENTS_API_PORT', 443);
if (!defined('CLIENTS_API_TOKEN')) define('CLIENTS_API_TOKEN', '');
if (!defined('CLIENTS_API_CONNECT_TIMEOUT')) define('CLIENTS_API_CONNECT_TIMEOUT', 3);
if (!defined('CLIENTS_API_TIMEOUT')) define('CLIENTS_API_TIMEOUT', 8);
if (!defined('CLIENTS_API_RESOLVE_LOCAL')) define('CLIENTS_API_RESOLVE_LOCAL', true);

function dbConfigValue(string $envName, string $constantName): string
{
    $envValue = getenv($envName);
    if ($envValue !== false && trim((string)$envValue) !== '') {
        return trim((string)$envValue);
    }
    return (string)constant($constantName);
}

function firstDbConfigValue(array $envNames, string $constantName): string
{
    foreach ($envNames as $envName) {
        $envValue = getenv($envName);
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }
    }
    return (string)constant($constantName);
}

function getDbConfig(): array
{
    return [
        'host' => firstDbConfigValue(['CALLTRACK_DB_HOST', 'DB_HOST', 'MYSQL_HOST', 'MYSQL_SERVER'], 'DB_HOST'),
        'db' => firstDbConfigValue(['CALLTRACK_DB_NAME', 'DB_NAME', 'MYSQL_DATABASE', 'MYSQL_DB'], 'DB_NAME'),
        'user' => firstDbConfigValue(['CALLTRACK_DB_USER', 'DB_USER', 'MYSQL_USER', 'MYSQL_USERNAME'], 'DB_USER'),
        'pass' => firstDbConfigValue(['CALLTRACK_DB_PASS', 'DB_PASS', 'MYSQL_PASSWORD', 'MYSQL_PASS'], 'DB_PASS'),
        'charset' => firstDbConfigValue(['CALLTRACK_DB_CHARSET', 'DB_CHARSET', 'MYSQL_CHARSET'], 'DB_CHARSET'),
    ];
}

function getPdo(): PDO
{
    $config = getDbConfig();
    $host = $config['host'];
    $db = $config['db'];
    $user = $config['user'];
    $pass = $config['pass'];
    $charset = $config['charset'];

    error_log('DB HOST: ' . $host);
    error_log('DB USER: ' . $user);

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $error) {
        if ($pass !== 'YOUR_PASSWORD') {
            throw $error;
        }
        error_log('DB PASS is default placeholder, retrying connection with empty password');
        return new PDO($dsn, $user, '', $options);
    }
}


function ensurePersonalContactsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS personal_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_phone VARCHAR(20),
    manager VARCHAR(255),
    contact_phone VARCHAR(20),
    updated_at DATETIME,
    personal_flag TINYINT(1),
    UNIQUE KEY uk_user_contact (user_phone, contact_phone),
    INDEX idx_personal_user_phone (user_phone),
    INDEX idx_personal_contact_phone (contact_phone),
    INDEX idx_personal_flag (personal_flag)
)
SQL);
}


function ensureUserTelemetryTables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_user_reports (
    user_phone VARCHAR(30) PRIMARY KEY,
    manager VARCHAR(255),
    last_activity DATETIME,
    app_version VARCHAR(50),
    installed_at DATETIME NULL,
    app_updated_at DATETIME NULL,
    last_launch_at DATETIME NULL,
    launch_count INT DEFAULT 0,
    device_manufacturer VARCHAR(100),
    device_model VARCHAR(100),
    android_version VARCHAR(50),
    api_level INT NULL,
    ram_total VARCHAR(50),
    storage_free VARCHAR(50),
    screen_resolution VARCHAR(50),
    device_language VARCHAR(50),
    timezone VARCHAR(100),
    calls_permission VARCHAR(20),
    notifications_permission VARCHAR(20),
    contacts_permission VARCHAR(20),
    background_permission VARCHAR(20),
    battery_optimization_ignored VARCHAR(20),
    google_play_services VARCHAR(50),
    sync_errors_count INT DEFAULT 0,
    local_db_size VARCHAR(50),
    last_error TEXT,
    last_server_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_user_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_phone VARCHAR(30) NOT NULL,
    manager VARCHAR(255),
    level VARCHAR(30),
    category VARCHAR(50),
    message TEXT,
    logged_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_logs_phone_time (user_phone, logged_at)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_user_commands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_phone VARCHAR(30) NOT NULL,
    command VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL,
    INDEX idx_user_commands_phone_status (user_phone, status)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_user_states (
    user_phone VARCHAR(30) PRIMARY KEY,
    user_key VARCHAR(255) UNIQUE,
    manager VARCHAR(255),
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
SQL);
    try {
        $pdo->exec('ALTER TABLE app_user_states ADD COLUMN user_key VARCHAR(255) NULL');
    } catch (Throwable $e) {
        // Колонка уже существует на обновлённой базе.
    }
    try {
        $pdo->exec('ALTER TABLE app_user_states ADD UNIQUE KEY uk_app_user_states_user_key (user_key)');
    } catch (Throwable $e) {
        // Индекс уже существует или БД не разрешила повторное добавление.
    }
}

function ensureAppUpdatesTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_updates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    version_name VARCHAR(50) NOT NULL,
    version_code INT NOT NULL,
    release_notes TEXT,
    mandatory TINYINT(1) NOT NULL DEFAULT 0,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL,
    INDEX idx_app_updates_version_code (version_code),
    INDEX idx_app_updates_uploaded_at (uploaded_at)
)
SQL);
}

function updateDownloadUrlForVersion(int $versionCode): string
{
    $suffix = $versionCode > 0 ? '&versionCode=' . rawurlencode((string)$versionCode) : '';
    return (string)UPDATE_DOWNLOAD_URL . $suffix;
}

function resolveUpdateApk(PDO $pdo, int $versionCode = 0): array
{
    ensureAppUpdatesTable($pdo);
    if ($versionCode > 0) {
        $stmt = $pdo->prepare('SELECT filename, version_code FROM app_updates WHERE version_code = :version_code ORDER BY uploaded_at DESC, id DESC LIMIT 1');
        $stmt->execute([':version_code' => $versionCode]);
    } else {
        $stmt = $pdo->query('SELECT filename, version_code FROM app_updates ORDER BY version_code DESC, uploaded_at DESC, id DESC LIMIT 1');
    }
    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['status' => 'error', 'message' => 'APK update not found'], 404);
    }

    $filename = basename((string)($row['filename'] ?? ''));
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'apk') {
        sendJson(['status' => 'error', 'message' => 'APK filename is invalid'], 500);
    }
    $updatesDir = realpath(dirname(__DIR__) . '/updates');
    $apkPath = realpath(dirname(__DIR__) . '/updates/' . $filename);
    if ($updatesDir === false || $apkPath === false || strpos($apkPath, $updatesDir) !== 0 || !is_file($apkPath)) {
        sendJson(['status' => 'error', 'message' => 'APK file not found'], 404);
    }
    return ['path' => $apkPath, 'filename' => $filename, 'version_code' => (int)$row['version_code']];
}

function streamApkFile(string $apkPath, string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($apkPath));
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($apkPath);
    exit;
}

function ensureEmailTables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_mailboxes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manager_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    imap_host VARCHAR(255) NOT NULL,
    imap_port INT NOT NULL DEFAULT 993,
    imap_ssl TINYINT(1) NOT NULL DEFAULT 1,
    username VARCHAR(255) NOT NULL,
    password_encrypted TEXT NOT NULL,
    inbox_folder VARCHAR(255) NOT NULL DEFAULT 'INBOX',
    sent_folder VARCHAR(255) NOT NULL DEFAULT 'Sent',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    sync_status ENUM('never','success','error') NOT NULL DEFAULT 'never',
    sync_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_mailboxes_email (email),
    INDEX idx_email_mailboxes_manager (manager_name),
    INDEX idx_email_mailboxes_enabled (enabled)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id BIGINT UNSIGNED NOT NULL,
    manager_name VARCHAR(255) NOT NULL,
    direction ENUM('incoming','outgoing') NOT NULL,
    sent_at DATETIME NOT NULL,
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    to_emails TEXT,
    cc_emails TEXT,
    client_name VARCHAR(255),
    client_email VARCHAR(255),
    client_status ENUM('found','not_found','needs_review') NOT NULL DEFAULT 'not_found',
    incoming_status ENUM('read','unread','answered') NOT NULL DEFAULT 'unread',
    outgoing_status ENUM('delivered','not_delivered','opened') NULL,
    subject TEXT,
    body_text MEDIUMTEXT,
    body_html MEDIUMTEXT,
    message_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    has_attachments TINYINT(1) NOT NULL DEFAULT 0,
    attachment_count INT UNSIGNED NOT NULL DEFAULT 0,
    imap_uid BIGINT UNSIGNED NOT NULL,
    imap_folder VARCHAR(255) NOT NULL DEFAULT 'INBOX',
    message_id VARCHAR(512),
    thread_key VARCHAR(512),
    answered_at DATETIME NULL,
    response_minutes INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_messages_mailbox_folder_uid (mailbox_id, imap_folder, imap_uid),
    INDEX idx_email_messages_sent_at (sent_at),
    INDEX idx_email_messages_manager (manager_name),
    INDEX idx_email_messages_client_email (client_email),
    INDEX idx_email_messages_status (client_status),
    INDEX idx_email_messages_direction (direction)
)
SQL);
    foreach ([
        "ALTER TABLE email_mailboxes ADD COLUMN sync_status ENUM('never','success','error') NOT NULL DEFAULT 'never'",
        "ALTER TABLE email_mailboxes ADD COLUMN sync_error TEXT NULL",
        "ALTER TABLE email_messages ADD COLUMN incoming_status ENUM('read','unread','answered') NOT NULL DEFAULT 'unread'",
        "ALTER TABLE email_messages ADD COLUMN outgoing_status ENUM('delivered','not_delivered','opened') NULL",
        "ALTER TABLE email_messages ADD COLUMN imap_folder VARCHAR(255) NOT NULL DEFAULT 'INBOX'",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Колонка уже существует.
        }
    }
    try {
        $pdo->exec('ALTER TABLE email_messages DROP INDEX uk_email_messages_mailbox_uid, ADD UNIQUE KEY uk_email_messages_mailbox_folder_uid (mailbox_id, imap_folder, imap_uid)');
    } catch (Throwable $e) {
        // Индекс уже приведён к схеме «ящик + папка + UID».
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(512) NOT NULL,
    mime_type VARCHAR(255),
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_path VARCHAR(1024),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_attachments_message (message_id)
)
SQL);
}

function encryptSecret(string $secret): string
{
    $key = getenv('CALLTRACK_SECRET_KEY') ?: getenv('APP_SECRET_KEY') ?: '';
    if ($key === '' || !function_exists('openssl_encrypt')) {
        return base64_encode($secret);
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($secret, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Не удалось зашифровать секрет');
    }
    return 'aes256cbc:' . base64_encode($iv . $cipher);
}

function decryptSecret(string $encrypted): string
{
    $isEncrypted = str_starts_with($encrypted, 'aes256cbc:');
    $payload = $isEncrypted ? substr($encrypted, strlen('aes256cbc:')) : $encrypted;
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('Некорректный формат зашифрованного пароля');
    }
    $key = getenv('CALLTRACK_SECRET_KEY') ?: getenv('APP_SECRET_KEY') ?: '';
    if (!$isEncrypted) {
        return $decoded;
    }
    if ($key === '' || !function_exists('openssl_decrypt') || strlen($decoded) < 17) {
        throw new RuntimeException('Для расшифровки IMAP-пароля не настроен ключ приложения');
    }
    $iv = substr($decoded, 0, 16);
    $secret = openssl_decrypt(substr($decoded, 16), 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($secret === false) {
        throw new RuntimeException('Не удалось расшифровать пароль IMAP');
    }
    return $secret;
}

function normalizeUserStateKey(?string $phone, ?string $manager = null, ?string $userId = null): string
{
    $userId = trim((string)$userId);
    if ($userId !== '') {
        return $userId;
    }
    $phone = trim((string)$phone);
    if ($phone !== '') {
        return 'phone:' . $phone;
    }
    return 'manager:' . trim((string)$manager);
}

function isUserBlocked(PDO $pdo, ?string $phone, ?string $manager = null, ?string $userId = null): bool
{
    try {
        ensureUserTelemetryTables($pdo);
        $phone = trim((string)$phone);
        $manager = trim((string)$manager);
        $userId = trim((string)$userId);
        if ($phone === '' && $manager === '' && $userId === '') {
            return false;
        }

        $where = [];
        $params = [];
        if ($userId !== '' || $phone !== '' || $manager !== '') {
            $where[] = 'user_key = :user_key';
            $params[':user_key'] = normalizeUserStateKey($phone, $manager, $userId);
        }
        if ($phone !== '') {
            $where[] = 'user_phone = :user_phone';
            $params[':user_phone'] = $phone;
        }
        $stmt = $pdo->prepare('SELECT is_blocked FROM app_user_states WHERE ' . implode(' OR ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}


function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        sendJson(['status' => 'error', 'message' => 'Некорректный JSON'], 400);
    }
    return $data;
}

function valueOrNull(array $data, string $key): mixed
{
    if (!array_key_exists($key, $data)) {
        return null;
    }
    if ($data[$key] === '') {
        return null;
    }
    return $data[$key];
}

function normalizeDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    foreach (['Y-m-d', 'd.m.y', 'd.m.Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if ($date instanceof DateTime && !$hasErrors) {
            return $date->format('Y-m-d');
        }
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function normalizeTime(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    foreach (['H:i:s', 'H:i'] as $format) {
        $time = DateTime::createFromFormat($format, $value);
        if ($time instanceof DateTime) {
            return $time->format('H:i:s');
        }
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('H:i:s', $timestamp);
}

function normalizeDateTime(mixed $value): ?string
{
    // MariaDB DATETIME не принимает пустую строку.
    // При отсутствии значения передаём NULL.
    if (empty($value)) {
        return null;
    }
    $timestamp = strtotime(trim((string)$value));
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function buildFilters(array $source, array &$params): string
{
    $where = [];
    foreach (['manager', 'phone', 'user_phone'] as $field) {
        if (!empty($source[$field])) {
            $where[] = "{$field} = :{$field}";
            $params[":{$field}"] = $source[$field];
        }
    }
    if (!empty($source['date_from'])) {
        $where[] = 'call_date >= :date_from';
        $params[':date_from'] = normalizeDate((string)$source['date_from']);
    }
    if (!empty($source['date_to'])) {
        $where[] = 'call_date <= :date_to';
        $params[':date_to'] = normalizeDate((string)$source['date_to']);
    }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}
