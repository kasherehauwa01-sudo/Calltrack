<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/web_auth.php';

try {
    $pdo=getPdo();
    requireWebAdmin($pdo);
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') sendJson(['status'=>'error','message'=>'Разрешён только POST'],405);
    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : $_GET;
    $idDb = valueOrNull($data, 'id_db');
    $callId = valueOrNull($data, 'call_id');
    if ($idDb === null && $callId === null) {
        sendJson(['status' => 'error', 'message' => 'Передайте id_db или call_id'], 400);
    }

    if ($idDb !== null) {
        $stmt = $pdo->prepare('DELETE FROM calls WHERE id_db = :id_db');
        $stmt->execute([':id_db' => (int)$idDb]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM calls WHERE call_id = :call_id');
        $stmt->execute([':call_id' => $callId]);
    }

    sendJson(['status' => 'success', 'deleted' => $stmt->rowCount()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
