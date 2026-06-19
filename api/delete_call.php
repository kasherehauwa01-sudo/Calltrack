<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : $_GET;
    $idDb = valueOrNull($data, 'id_db');
    $callId = valueOrNull($data, 'call_id');
    if ($idDb === null && $callId === null) {
        sendJson(['status' => 'error', 'message' => 'Передайте id_db или call_id'], 400);
    }

    if ($idDb !== null) {
        $stmt = getPdo()->prepare('DELETE FROM calls WHERE id_db = :id_db');
        $stmt->execute([':id_db' => (int)$idDb]);
    } else {
        $stmt = getPdo()->prepare('DELETE FROM calls WHERE call_id = :call_id');
        $stmt->execute([':call_id' => $callId]);
    }

    sendJson(['status' => 'success', 'deleted' => $stmt->rowCount()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
