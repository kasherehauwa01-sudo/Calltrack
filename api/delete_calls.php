<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $data = readJsonBody();
    $ids = $data['ids'] ?? [];
    $callIds = $data['call_ids'] ?? [];
    if (!is_array($ids) || !is_array($callIds)) {
        sendJson(['status' => 'error', 'message' => 'ids и call_ids должны быть массивами'], 400);
    }

    $pdo = getPdo();
    $deleted = 0;
    if ($ids !== []) {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM calls WHERE id_db IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted += $stmt->rowCount();
        }
    }

    if ($callIds !== []) {
        $callIds = array_values(array_filter(array_map('strval', $callIds), static fn(string $id): bool => trim($id) !== ''));
        if ($callIds !== []) {
            $placeholders = implode(',', array_fill(0, count($callIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM calls WHERE call_id IN ({$placeholders})");
            $stmt->execute($callIds);
            $deleted += $stmt->rowCount();
        }
    }

    sendJson(['status' => 'success', 'deleted' => $deleted]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
