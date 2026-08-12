<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';

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

function firstRowValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            return (string)$row[$key];
        }
    }
    return '';
}

function normalizeCallRow(array $row): array
{
    return [
        'id_db' => firstRowValue($row, ['id_db', 'id', 'ID']),
        'call_date' => firstRowValue($row, ['call_date', 'date', 'Дата']),
        'call_time' => firstRowValue($row, ['call_time', 'time', 'Время']),
        'phone' => firstRowValue($row, ['phone', 'Номер телефона', 'Телефон']),
        'call_type' => firstRowValue($row, ['call_type', 'type', 'Тип звонка', 'Тип']),
        'duration' => firstRowValue($row, ['duration', 'Длительность']),
        'manager' => firstRowValue($row, ['manager', 'Менеджер']),
        'client' => firstRowValue($row, ['client', 'Клиент']),
        'comment' => firstRowValue($row, ['comment', 'Комментарий']),
        'tag' => firstRowValue($row, ['tag', 'Тег']),
        'reminder' => firstRowValue($row, ['reminder', 'Напоминание']),
        'reminder_text' => firstRowValue($row, ['reminder_text', 'Текст напоминания']),
        'call_id' => firstRowValue($row, ['call_id', 'ID звонка']),
        'user_phone' => firstRowValue($row, ['user_phone', 'manager_phone', 'Номер телефона пользователя']),
        'created_at' => firstRowValue($row, ['created_at', 'Создано']),
    ];
}

function rowMatchesFilters(array $row, array $filters): bool
{
    $manager = trim((string)($filters['manager'] ?? ''));
    if ($manager !== '' && (string)$row['manager'] !== $manager) {
        return false;
    }

    $phone = trim((string)($filters['phone'] ?? ''));
    if ($phone !== '' && (string)$row['phone'] !== $phone) {
        return false;
    }

    $userPhone = trim((string)($filters['user_phone'] ?? ''));
    if ($userPhone !== '' && (string)$row['user_phone'] !== $userPhone) {
        return false;
    }

    $date = normalizeDate((string)$row['call_date']);
    if (!empty($filters['date_from']) && ($date === null || $date < normalizeDate((string)$filters['date_from']))) {
        return false;
    }
    if (!empty($filters['date_to']) && ($date === null || $date > normalizeDate((string)$filters['date_to']))) {
        return false;
    }
    return true;
}

function enrichCallsWithClients(array $rows, PDO $pdo): array
{
    try {
        $clientIndex = buildClientPhoneIndex(loadClientsDirectory($pdo, false));
        if (!$clientIndex) return $rows;

        foreach ($rows as &$row) {
            $normalizedPhone = normalizeClientPhone((string)$row['phone']);
            if ($normalizedPhone !== '' && isset($clientIndex[$normalizedPhone])) {
                $row['client'] = $clientIndex[$normalizedPhone];
            }
        }
        unset($row);
    } catch (Throwable $e) {
        // Справочник Clients обогащает звонки, но его недоступность не должна
        // останавливать основной дашборд. Возвращаем клиент из таблицы calls.
        error_log('Calls client enrichment failed: ' . $e->getMessage());
    }
    return $rows;
}

try {
    $filters = applyRegistryPeriod($_GET);
    $rawLimit = $_GET['limit'] ?? null;
    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    $loadAll = $period === 'all' || $rawLimit === null || (int)$rawLimit === 0;
    $limit = $loadAll ? null : min(max((int)$rawLimit, 1), 1000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $pdo = getPdo();
    $stmt = $pdo->query('SELECT * FROM calls');
    $rows = array_map('normalizeCallRow', $stmt->fetchAll());
    $rows = enrichCallsWithClients($rows, $pdo);
    $rows = array_values(array_filter($rows, static fn(array $row): bool => rowMatchesFilters($row, $filters)));
    usort($rows, static function (array $a, array $b): int {
        $left = sprintf('%s %s %012d', $a['call_date'], $a['call_time'], (int)$a['id_db']);
        $right = sprintf('%s %s %012d', $b['call_date'], $b['call_time'], (int)$b['id_db']);
        return $right <=> $left;
    });

    $total = count($rows);
    if (!$loadAll) {
        $rows = array_slice($rows, $offset, $limit);
    }

    sendJson(['status' => 'success', 'data' => $rows, 'total' => $total]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
