<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/android_auth.php';
require_once __DIR__ . '/max_messages_service.php';

// Match the existing Android-auth HTTPS/proxy convention.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$pdo = null;
$authenticate = static function () use (&$pdo): ?array {
    if (bearerToken() === '') return null;
    $pdo = getPdo();
    return currentAndroidUser($pdo);
};
$persist = static function (array $messages, array $identity) use (&$pdo): array {
    if (isUserBlocked($pdo, $identity['user_phone'], $identity['manager'])) {
        throw new RuntimeException('Account unavailable');
    }
    return storeMaxMessages($pdo, $messages, $identity);
};
// Bounded read even for chunked requests or an incorrect Content-Length.
$body = file_get_contents('php://input', false, null, 0, 262145);
[$status, $response] = handleMaxMessages($_SERVER['REQUEST_METHOD'] ?? '', $https, $body === false ? '' : $body,
    $authenticate, 'androidManagerIdentity', $persist);
if ($status === 405) header('Allow: POST');
sendJson($response, $status);
