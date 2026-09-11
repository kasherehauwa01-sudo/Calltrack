<?php
declare(strict_types=1);

require_once __DIR__.'/web_auth.php';

function ensureAndroidAuthTables(PDO $pdo): void
{
    ensureWebAuthTables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_user_tokens (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        web_user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY uk_web_user_tokens_hash (token_hash),
        INDEX idx_web_user_tokens_user (web_user_id),
        INDEX idx_web_user_tokens_expiry (expires_at,revoked_at),
        CONSTRAINT fk_web_user_tokens_user FOREIGN KEY (web_user_id) REFERENCES web_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function bearerToken(): string
{
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if($header===''&&function_exists('getallheaders'))$header=(string)(getallheaders()['Authorization']??'');
    return preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,})$/i',trim($header),$match) ? $match[1] : '';
}

function currentAndroidUser(PDO $pdo): ?array
{
    $raw=bearerToken();
    if($raw==='')return null;
    ensureAndroidAuthTables($pdo);
    $stmt=$pdo->prepare("SELECT t.id token_id,w.id,w.display_name,w.login,w.role,w.is_active
        FROM web_user_tokens t JOIN web_users w ON w.id=t.web_user_id
        WHERE t.token_hash=:hash AND t.revoked_at IS NULL AND t.expires_at>NOW() LIMIT 1");
    $stmt->execute([':hash'=>hash('sha256',$raw)]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$user||(int)$user['is_active']!==1)return null;
    $pdo->prepare('UPDATE web_user_tokens SET last_used_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE id=:id')->execute([':id'=>$user['token_id']]);
    unset($user['token_id'],$user['is_active']);return $user;
}

function optionalAndroidUser(PDO $pdo): ?array
{
    if(bearerToken()==='')return null;
    $user=currentAndroidUser($pdo);
    if(!$user)sendJson(['status'=>'error','message'=>'Android-сессия недействительна'],401);
    return $user;
}

function androidManagerIdentity(array $user): array
{
    return ['user_phone'=>webUserPhone((int)$user['id']),'manager'=>(string)$user['display_name']];
}

function revokeAndroidTokens(PDO $pdo,int $userId): void
{
    ensureAndroidAuthTables($pdo);
    $pdo->prepare('UPDATE web_user_tokens SET revoked_at=NOW() WHERE web_user_id=:id AND revoked_at IS NULL')->execute([':id'=>$userId]);
}
