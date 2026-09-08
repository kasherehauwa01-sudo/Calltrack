<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/web_auth.php';

try {
    $pdo = getPdo(); $user = requireWebUser($pdo); $filters=$_GET;
    $scope=webManagerScope($user); if($scope)$filters['user_phone']=$scope['user_phone'];
    $params = [];
    $where = buildFilters($filters, $params);
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetch() ?: [];
    $data['average_duration'] = isset($data['average_duration']) ? round((float)$data['average_duration'], 2) : 0;
    sendJson(['status' => 'success', 'data' => $data]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
