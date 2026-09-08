<?php
declare(strict_types=1);

function ensureWebAuthTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        display_name VARCHAR(255) NOT NULL,
        login VARCHAR(254) NOT NULL,
        pin_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','manager') NOT NULL,
        manager_user_phone VARCHAR(30) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_web_users_login (login),
        INDEX idx_web_users_manager (manager_user_phone),
        INDEX idx_web_users_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(254) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_web_login_attempt (login, ip_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['ALTER TABLE web_users MODIFY login VARCHAR(254) NOT NULL', 'ALTER TABLE web_login_attempts MODIFY login VARCHAR(254) NOT NULL'] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* Размер уже актуален или ALTER запрещён. */ }
    }
}

function normalizeWebLoginEmail(string $value): string
{
    $email = strtolower(trim($value));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function startWebSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('calltrack_web');
    session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite'=>'Lax', 'path'=>'/']);
    session_start();
}

function currentWebUser(PDO $pdo): ?array
{
    startWebSession();
    ensureUserTelemetryTables($pdo);
    $id = (int)($_SESSION['web_user_id'] ?? 0);
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT w.id,w.display_name,w.login,w.role,w.manager_user_phone,w.is_active,
        COALESCE(NULLIF(r.manager,''), NULLIF(s.manager,''), '') AS manager_name
        FROM web_users w
        LEFT JOIN app_user_reports r ON r.user_phone=w.manager_user_phone
        LEFT JOIN app_user_states s ON s.user_phone=w.manager_user_phone
        WHERE w.id=:id LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active']) { $_SESSION=[]; return null; }
    unset($user['is_active']);
    return $user;
}

function requireWebUser(PDO $pdo): array
{
    $user = currentWebUser($pdo);
    if (!$user) sendJson(['status'=>'error','message'=>'Требуется авторизация'], 401);
    return $user;
}

function requireWebAdmin(PDO $pdo): array
{
    $user = requireWebUser($pdo);
    if ($user['role'] !== 'admin') sendJson(['status'=>'error','message'=>'Доступ разрешён только администратору'], 403);
    return $user;
}

function webManagerScope(array $user): ?array
{
    if ($user['role'] === 'admin') return null;
    return ['user_phone'=>(string)$user['manager_user_phone'], 'manager'=>(string)$user['manager_name']];
}
