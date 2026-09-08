<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/android_auth.php';

try {
    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    $data = readJsonBody();
    $androidUser=optionalAndroidUser($pdo);$identity=$androidUser?androidManagerIdentity($androidUser):null;
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) sendJson(['status' => 'error', 'message' => 'Передайте id команды'], 400);
    $stmt = $pdo->prepare("UPDATE app_user_commands SET status = 'done', executed_at = NOW() WHERE id = :id".($identity?' AND user_phone=:phone':''));
    $params=[':id'=>$id];if($identity)$params[':phone']=$identity['user_phone'];$stmt->execute($params);
    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
