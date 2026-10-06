<?php
declare(strict_types=1);

function ensureWebAuthTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        display_name VARCHAR(255) NOT NULL,
        login VARCHAR(254) NOT NULL,
        pin_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','supervisor','manager') NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_web_users_login (login),
        INDEX idx_web_users_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(254) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_web_login_attempt (login, ip_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_security_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        web_user_id BIGINT UNSIGNED NULL,
        actor_login VARCHAR(254) NULL,
        event_type VARCHAR(80) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        metadata_json TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_web_security_audit_created (created_at),
        INDEX idx_web_security_audit_user (web_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['ALTER TABLE web_users MODIFY login VARCHAR(254) NOT NULL', 'ALTER TABLE web_login_attempts MODIFY login VARCHAR(254) NOT NULL'] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* Размер уже актуален или ALTER запрещён. */ }
    }
    try { $pdo->exec("ALTER TABLE web_users MODIFY role ENUM('admin','supervisor','manager') NOT NULL"); } catch (Throwable $e) { /* При отсутствии ALTER применяется штатная миграция. */ }
    // Поле старой ручной привязки больше не используется: Android и web
    // идентифицируют одного пользователя по стабильному ID учётной записи.
    try { $pdo->exec('ALTER TABLE web_users DROP INDEX idx_web_users_manager'); } catch (Throwable $e) { /* Индекс уже удалён. */ }
    try { $pdo->exec('ALTER TABLE web_users DROP COLUMN manager_user_phone'); } catch (Throwable $e) { /* Поле уже удалено. */ }
}

function normalizeWebLoginEmail(string $value): string
{
    $email = strtolower(trim($value));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function startWebSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('calltrack_web');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    session_set_cookie_params(['httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax', 'path'=>'/']);
    session_start();
}

function destroyWebSession(): void
{
    startWebSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'=>time()-42000,
            'path'=>$params['path'] ?: '/',
            'domain'=>$params['domain'] ?? '',
            'secure'=>(bool)$params['secure'],
            'httponly'=>(bool)$params['httponly'],
            'samesite'=>$params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function webCsrfToken(): string
{
    startWebSession();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 43) {
        $_SESSION['csrf_token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    return $_SESSION['csrf_token'];
}

function requireWebCsrf(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    startWebSession();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!isValidWebCsrfToken($expected, $provided)) {
        sendJson(['status'=>'error','message'=>'Недействительный CSRF-токен'], 403);
    }
}

function isValidWebCsrfToken(string $expected, string $provided): bool
{
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function recordWebSecurityEvent(PDO $pdo, string $eventType, string $outcome, ?int $userId=null, string $login='', array $metadata=[]): void
{
    try {
        $stmt=$pdo->prepare('INSERT INTO web_security_audit(web_user_id,actor_login,event_type,outcome,ip_hash,metadata_json) VALUES(:user_id,:login,:event,:outcome,:ip,:metadata)');
        $stmt->execute([
            ':user_id'=>$userId,
            ':login'=>$login !== '' ? mb_substr($login, 0, 254) : null,
            ':event'=>mb_substr($eventType, 0, 80),
            ':outcome'=>mb_substr($outcome, 0, 20),
            ':ip'=>hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')),
            ':metadata'=>$metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $error) {
        error_log('Web security audit unavailable: '.$error->getMessage());
    }
}

function currentWebUser(PDO $pdo): ?array
{
    startWebSession();
    ensureUserTelemetryTables($pdo);
    $id = (int)($_SESSION['web_user_id'] ?? 0);
    if ($id <= 0) return null;
    $now = time();
    $createdAt = (int)($_SESSION['created_at'] ?? $now);
    $lastSeenAt = (int)($_SESSION['last_seen_at'] ?? $now);
    if (($now-$lastSeenAt)>7200 || ($now-$createdAt)>43200) {
        destroyWebSession();
        return null;
    }
    $_SESSION['created_at']=$createdAt;
    $_SESSION['last_seen_at']=$now;
    $stmt = $pdo->prepare('SELECT id,display_name,login,role,is_active FROM web_users WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$id]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active']) { destroyWebSession(); return null; }
    unset($user['is_active']);
    return $user;
}

function requireWebUser(PDO $pdo, bool $requireCsrf=true): array
{
    $user = currentWebUser($pdo);
    if (!$user) sendJson(['status'=>'error','message'=>'Требуется авторизация'], 401);
    if ($requireCsrf) requireWebCsrf();
    return $user;
}

function requireWebAdmin(PDO $pdo): array
{
    $user = requireWebUser($pdo);
    if ($user['role'] !== 'admin') sendJson(['status'=>'error','message'=>'Доступ разрешён только администратору'], 403);
    return $user;
}

function requireWebRole(PDO $pdo, array $roles): array
{
    $user=requireWebUser($pdo);
    if (!in_array((string)$user['role'], $roles, true)) sendJson(['status'=>'error','message'=>'Недостаточно прав'],403);
    return $user;
}

function webManagerScope(array $user): ?array
{
    if (in_array($user['role'], ['admin', 'supervisor'], true)) return null;
    return ['user_phone'=>webUserPhone((int)$user['id']), 'manager'=>(string)$user['display_name']];
}

function managerNameKey(string $name): string
{
    $parts = preg_split('/\s+/u', mb_strtolower(trim($name))) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    sort($parts, SORT_STRING);
    return implode("\0", $parts);
}

function canonicalManagerNames(PDO $pdo): array
{
    $names = $pdo->query("SELECT display_name FROM web_users WHERE is_active=1 AND role='manager'")->fetchAll(PDO::FETCH_COLUMN);
    $byKey = [];
    foreach ($names as $name) $byKey[managerNameKey((string)$name)][] = (string)$name;
    $result = [];
    foreach ($byKey as $key => $matches) if (count($matches) === 1) $result[$key] = $matches[0];
    return $result;
}

function canonicalManagerName(string $name, array $canonicalNames): string
{
    return $canonicalNames[managerNameKey($name)] ?? trim($name);
}

function webUserPhone(int $userId): string
{
    return 'web-user-'.$userId;
}
