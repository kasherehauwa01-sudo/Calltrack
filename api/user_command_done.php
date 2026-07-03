<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    $data = readJsonBody();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) sendJson(['status' => 'error', 'message' => 'Передайте id команды'], 400);
    $stmt = $pdo->prepare("UPDATE app_user_commands SET status = 'done', executed_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);
    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
