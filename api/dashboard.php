<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $params = [];
    $where = buildFilters($_GET, $params);
    $sql = <<<SQL
SELECT
    COUNT(*) AS total_calls,
    SUM(CASE WHEN call_type = 'Входящий' THEN 1 ELSE 0 END) AS incoming_calls,
    SUM(CASE WHEN call_type = 'Исходящий' THEN 1 ELSE 0 END) AS outgoing_calls,
    SUM(CASE WHEN call_type IN ('Пропущенный', 'Сброшенный', 'Неотвеченный') THEN 1 ELSE 0 END) AS missed_calls,
    AVG(duration) AS average_duration,
    SUM(CASE WHEN comment IS NOT NULL AND comment <> '' THEN 1 ELSE 0 END) AS comments_count,
    SUM(CASE WHEN reminder IS NOT NULL OR (reminder_text IS NOT NULL AND reminder_text <> '') THEN 1 ELSE 0 END) AS reminders_count
FROM calls{$where}
SQL;

    $stmt = getPdo()->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetch() ?: [];
    $data['average_duration'] = isset($data['average_duration']) ? round((float)$data['average_duration'], 2) : 0;
    sendJson(['status' => 'success', 'data' => $data]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
