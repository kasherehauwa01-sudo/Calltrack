<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $data = readJsonBody();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        sendJson(['status' => 'error', 'message' => 'Поле ids должно быть массивом'], 400);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        sendJson(['status' => 'error', 'message' => 'Передайте id записей для удаления'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getPdo()->prepare("DELETE FROM personal_contacts WHERE id IN ({$placeholders})");
    $stmt->execute($ids);

    sendJson(['status' => 'success', 'deleted' => $stmt->rowCount()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
