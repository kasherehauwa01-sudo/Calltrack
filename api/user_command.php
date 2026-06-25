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
    if (!in_array($command, ['force_sync'], true)) {
        sendJson(['status' => 'error', 'message' => 'Неизвестная команда'], 400);
    }
    $stmt = $pdo->prepare("INSERT INTO app_user_commands (user_phone, command, status) VALUES (:user_phone, :command, 'pending')");
    $stmt->execute([':user_phone' => $userPhone, ':command' => $command]);
    sendJson(['status' => 'success', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
