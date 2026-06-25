<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    $data = readJsonBody();
    $userPhone = trim((string)($data['user_phone'] ?? ''));
    $command = trim((string)($data['command'] ?? ''));
    if ($userPhone === '' || $command === '') {
        sendJson(['status' => 'error', 'message' => 'Поля user_phone и command обязательны'], 400);
    }
    if (!in_array($command, ['force_sync', 'block_user', 'unblock_user', 'delete_user'], true)) {
        sendJson(['status' => 'error', 'message' => 'Неизвестная команда'], 400);
    }

    if ($command === 'delete_user') {
        $stateStmt = $pdo->prepare("INSERT INTO app_user_states (user_phone, is_deleted) VALUES (:user_phone, 1) ON DUPLICATE KEY UPDATE is_deleted = 1");
        $stateStmt->execute([':user_phone' => $userPhone]);
        sendJson(['status' => 'success']);
    }

    if ($command === 'block_user' || $command === 'unblock_user') {
        $isBlocked = $command === 'block_user' ? 1 : 0;
        $stateStmt = $pdo->prepare("INSERT INTO app_user_states (user_phone, is_blocked, is_deleted) VALUES (:user_phone, :is_blocked_insert, 0) ON DUPLICATE KEY UPDATE is_blocked = :is_blocked_update, is_deleted = 0");
        $stateStmt->execute([':user_phone' => $userPhone, ':is_blocked_insert' => $isBlocked, ':is_blocked_update' => $isBlocked]);
    }

    $stmt = $pdo->prepare("INSERT INTO app_user_commands (user_phone, command, status) VALUES (:user_phone, :command, 'pending')");
    $stmt->execute([':user_phone' => $userPhone, ':command' => $command]);
    sendJson(['status' => 'success', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
