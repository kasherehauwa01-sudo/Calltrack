<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function applyRegistryPeriod(array $source): array
{
    if (!empty($source['date_from']) || !empty($source['date_to'])) {
        return $source;
    }

    if (!array_key_exists('period', $source) || trim((string)$source['period']) === '') {
        return $source;
    }

    $period = strtolower(trim((string)$source['period']));
    $today = new DateTimeImmutable('today');
    $from = $today;
    $to = $today;

    switch ($period) {
        case 'yesterday':
        case 'вчера':
        case 'Вчера':
            $from = $today->modify('-1 day');
            $to = $from;
            break;
        case 'week':
        case 'неделя':
        case 'Неделя':
            $from = $today->modify('monday this week');
            break;
        case 'month':
        case 'месяц':
        case 'Месяц':
            $from = $today->modify('first day of this month');
            break;
        case 'year':
        case 'год':
        case 'Год':
            $from = $today->setDate((int)$today->format('Y'), 1, 1);
            break;
        case 'today':
        case 'сегодня':
        case 'Сегодня':
        default:
            $from = $today;
            $to = $today;
            break;
    }

    $source['date_from'] = $from->format('Y-m-d');
    $source['date_to'] = $to->format('Y-m-d');
    return $source;
}

try {
    $params = [];
    $filters = applyRegistryPeriod($_GET);
    $where = buildFilters($filters, $params);
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $sql = <<<SQL
SELECT id_db, call_date, call_time, phone, call_type, duration, manager, client,
       comment, tag, reminder, reminder_text, call_id, user_phone, created_at
FROM calls{$where}
ORDER BY call_date DESC, call_time DESC, id_db DESC
LIMIT :limit OFFSET :offset
SQL;
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
