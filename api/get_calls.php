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

function enrichCallsWithClients(array $rows): array
{
    try {
        // Основной дашборд читает компактный индекс телефон => наименование,
        // а не многомегабайтный кэш полных карточек Clients.
        $clientIndex = readClientsPhoneIndexCache();
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
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM calls{$where}");
    foreach ($params as $key => $value) $countStmt->bindValue($key, $value);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id_db, call_date, call_time, phone, call_type, duration, manager, client, comment, tag, reminder, reminder_text, call_id, user_phone, created_at FROM calls{$where} ORDER BY call_date DESC, call_time DESC, id_db DESC";
    if (!$loadAll) {
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    if (!$loadAll) {
        $rows = array_slice($rows, $offset, $limit);
    }
    $stmt->execute();
    $rows = enrichCallsWithClients($stmt->fetchAll());

    sendJson(['status' => 'success', 'data' => $rows, 'total' => $total]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
