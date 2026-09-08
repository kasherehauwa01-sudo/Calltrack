<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/web_auth.php';
require_once dirname(__DIR__) . '/api/android_auth.php';

function findWebUserForPinReset(PDO $pdo, string $login): ?array
{
    $stmt = $pdo->prepare('SELECT id, login FROM web_users WHERE login = :login LIMIT 1');
    $stmt->execute([':login' => strtolower(trim($login))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($user) ? $user : null;
}

function resetWebUserPin(PDO $pdo, int $userId, string $pin): void
{
    if (!preg_match('/^\d{4,12}$/', $pin)) {
        throw new InvalidArgumentException('PIN должен содержать от 4 до 12 цифр');
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    if ($hash === false) throw new RuntimeException('Не удалось создать hash PIN');
    $stmt = $pdo->prepare('UPDATE web_users SET pin_hash = :pin_hash WHERE id = :id');
    $stmt->execute([':pin_hash' => $hash, ':id' => $userId]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') revokeAndroidTokens($pdo, $userId);
}

function readHiddenPin(): string
{
    fwrite(STDOUT, 'Новый PIN (4–12 цифр): ');
    $terminal = function_exists('shell_exec') && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($terminal) @shell_exec('stty -echo');
    try { return trim((string)fgets(STDIN)); }
    finally { if ($terminal) { @shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); } }
}

function runResetWebUserPin(array $arguments): void
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Скрипт доступен только из CLI');
    $login = strtolower(trim((string)($arguments[1] ?? '')));
    if ($login === '') throw new InvalidArgumentException('Использование: php scripts/reset_web_user_pin.php LOGIN');
    $pdo = getPdo();
    $user = findWebUserForPinReset($pdo, $login);
    if ($user === null) throw new RuntimeException("Web-пользователь {$login} не найден");
    resetWebUserPin($pdo, (int)$user['id'], readHiddenPin());
    fwrite(STDOUT, "PIN пользователя {$user['login']} изменен" . PHP_EOL);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try { runResetWebUserPin($argv); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
}
