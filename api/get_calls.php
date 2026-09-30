<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';
require_once __DIR__ . '/web_auth.php';
require_once __DIR__ . '/android_auth.php';

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

function dashboardManagerDirectory(PDO $pdo, array $user): array
{
    $scope = webManagerScope($user);
    if ($scope) {
        return [['display_name'=>(string)$scope['manager'], 'user_phone'=>(string)$scope['user_phone']]];
    }
    $stmt = $pdo->query("SELECT id,display_name FROM web_users WHERE role='manager' AND is_active=1 ORDER BY display_name");
    return array_map(static fn(array $row): array => [
        'display_name'=>(string)$row['display_name'],
        'user_phone'=>webUserPhone((int)$row['id']),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

try {
    $pdo = getPdo();
    $androidUser = optionalAndroidUser($pdo);
    $filters = applyRegistryPeriod($_GET);
    if ($androidUser) {
        // Bearer identity always wins over query-string filters, so Android
        // analytics cannot request another manager's journal.
        $filters['user_phone'] = androidManagerIdentity($androidUser)['user_phone'];
        $managerDirectory = [['display_name'=>(string)$androidUser['display_name'], 'user_phone'=>webUserPhone((int)$androidUser['id'])]];
    } else {
        $webUser = requireWebUser($pdo);
        $scope = webManagerScope($webUser);
        if ($scope) $filters['user_phone'] = $scope['user_phone'];
        $managerDirectory = dashboardManagerDirectory($pdo, $webUser);
    }
    $params = [];
    $where = buildFilters($filters, $params);
    $rawLimit = $_GET['limit'] ?? null;
    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    $loadAll = $period === 'all' || $rawLimit === null || (int)$rawLimit === 0;
    $limit = $loadAll ? null : min(max((int)$rawLimit, 1), 1000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

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
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = enrichCallsWithClients($stmt->fetchAll());

    sendJson(['status' => 'success', 'data' => $rows, 'total' => $total, 'managers'=>$managerDirectory]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
