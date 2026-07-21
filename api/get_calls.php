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
        case 'all':
        case 'все':
        case 'Все':
            return $source;
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

function loadCallsColumns(PDO $pdo): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM calls')->fetchAll() as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

function hasCallsColumn(array $columns, string $column): bool
{
    return isset($columns[$column]);
}

function callColumnExpr(array $columns, string $column, ?string $fallback = null): string
{
    if (hasCallsColumn($columns, $column)) {
        return $column;
    }
    if ($fallback !== null && hasCallsColumn($columns, $fallback)) {
        return $fallback . ' AS ' . $column;
    }
    return 'NULL AS ' . $column;
}

function buildCallsFilters(array $source, array &$params, array $columns): string
{
    $where = [];
    foreach (['manager', 'phone', 'user_phone'] as $field) {
        if (!empty($source[$field]) && hasCallsColumn($columns, $field)) {
            $where[] = "{$field} = :{$field}";
            $params[":{$field}"] = $source[$field];
        }
    }
    if (!empty($source['date_from']) && hasCallsColumn($columns, 'call_date')) {
        $where[] = 'call_date >= :date_from';
        $params[':date_from'] = normalizeDate((string)$source['date_from']);
    }
    if (!empty($source['date_to']) && hasCallsColumn($columns, 'call_date')) {
        $where[] = 'call_date <= :date_to';
        $params[':date_to'] = normalizeDate((string)$source['date_to']);
    }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function callsOrderBy(array $columns): string
{
    $order = [];
    if (hasCallsColumn($columns, 'call_date')) {
        $order[] = 'call_date DESC';
    }
    if (hasCallsColumn($columns, 'call_time')) {
        $order[] = 'call_time DESC';
    }
    if (hasCallsColumn($columns, 'id_db')) {
        $order[] = 'id_db DESC';
    } elseif (hasCallsColumn($columns, 'id')) {
        $order[] = 'id DESC';
    }
    return $order ? implode(', ', $order) : '1';
}

try {
    $params = [];
    $filters = applyRegistryPeriod($_GET);
    $rawLimit = $_GET['limit'] ?? null;
    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    $loadAll = $period === 'all' || $rawLimit === null || (int)$rawLimit === 0;
    $limit = $loadAll ? null : min(max((int)$rawLimit, 1), 1000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $pdo = getPdo();
    $columns = loadCallsColumns($pdo);
    $where = buildCallsFilters($filters, $params, $columns);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM calls{$where}");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $selectColumns = [
        callColumnExpr($columns, 'id_db', 'id'),
        callColumnExpr($columns, 'call_date'),
        callColumnExpr($columns, 'call_time'),
        callColumnExpr($columns, 'phone'),
        callColumnExpr($columns, 'call_type'),
        callColumnExpr($columns, 'duration'),
        callColumnExpr($columns, 'manager'),
        callColumnExpr($columns, 'client'),
        callColumnExpr($columns, 'comment'),
        callColumnExpr($columns, 'tag'),
        callColumnExpr($columns, 'reminder'),
        callColumnExpr($columns, 'reminder_text'),
        callColumnExpr($columns, 'call_id'),
        callColumnExpr($columns, 'user_phone'),
        callColumnExpr($columns, 'created_at'),
    ];
    $sql = 'SELECT ' . implode(', ', $selectColumns) . " FROM calls{$where} ORDER BY " . callsOrderBy($columns);
    if (!$loadAll) {
        $sql .= "\nLIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if (!$loadAll) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();

    sendJson(['status' => 'success', 'data' => $stmt->fetchAll(), 'total' => $total]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
