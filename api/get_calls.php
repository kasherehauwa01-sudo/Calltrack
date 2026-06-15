<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $params = [];
    $where = buildFilters($_GET, $params);
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $sql = "SELECT * FROM calls{$where} ORDER BY call_date DESC, call_time DESC, id_db DESC LIMIT :limit OFFSET :offset";
    $stmt = getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
