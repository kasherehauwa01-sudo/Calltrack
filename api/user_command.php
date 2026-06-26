<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    $data = readJsonBody();
    $userPhone = trim((string)($data['user_phone'] ?? ''));
    $manager = trim((string)($data['manager'] ?? ''));
    $userId = trim((string)($data['user_id'] ?? ''));
    $userKey = normalizeUserStateKey($userPhone, $manager, $userId);
    $statePhone = $userPhone !== '' ? $userPhone : $userKey;
    $command = trim((string)($data['command'] ?? ''));
    if ($userKey === '' || $command === '') {
        sendJson(['status' => 'error', 'message' => 'Поля user_phone/user_id и command обязательны'], 400);
    }
    if (!in_array($command, ['force_sync', 'block_user', 'unblock_user', 'delete_user'], true)) {
        sendJson(['status' => 'error', 'message' => 'Неизвестная команда'], 400);
    }

    if ($command === 'delete_user') {
        $stateStmt = $pdo->prepare("INSERT INTO app_user_states (user_phone, user_key, manager, is_deleted) VALUES (:user_phone, :user_key, :manager, 1) ON DUPLICATE KEY UPDATE user_key = VALUES(user_key), manager = VALUES(manager), is_deleted = 1");
        $stateStmt->execute([':user_phone' => $statePhone, ':user_key' => $userKey, ':manager' => $manager]);
        sendJson(['status' => 'success']);
    }

    if ($command === 'block_user' || $command === 'unblock_user') {
        $isBlocked = $command === 'block_user' ? 1 : 0;
        $stateStmt = $pdo->prepare("INSERT INTO app_user_states (user_phone, user_key, manager, is_blocked, is_deleted) VALUES (:user_phone, :user_key, :manager, :is_blocked_insert, 0) ON DUPLICATE KEY UPDATE user_key = VALUES(user_key), manager = VALUES(manager), is_blocked = :is_blocked_update, is_deleted = 0");
        $stateStmt->execute([':user_phone' => $statePhone, ':user_key' => $userKey, ':manager' => $manager, ':is_blocked_insert' => $isBlocked, ':is_blocked_update' => $isBlocked]);
    }

    if ($userPhone === '') {
        sendJson(['status' => 'success']);
    }

    $stmt = $pdo->prepare("INSERT INTO app_user_commands (user_phone, command, status) VALUES (:user_phone, :command, 'pending')");
    $stmt->execute([':user_phone' => $userPhone, ':command' => $command]);
    sendJson(['status' => 'success', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
