<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function normalizeUserKey(?string $phone, ?string $manager): string
{
    $phone = trim((string)$phone);
    if ($phone !== '') {
        return 'phone:' . $phone;
    }
    return 'manager:' . trim((string)$manager);
}

try {
    $pdo = getPdo();
    $users = [];

    $callsStmt = $pdo->query(<<<'SQL'
SELECT
    COALESCE(NULLIF(user_phone, ''), '') AS user_phone,
    COALESCE(NULLIF(manager, ''), 'Не указан') AS manager,
    MAX(CONCAT(COALESCE(call_date, '1970-01-01'), ' ', COALESCE(call_time, '00:00:00'))) AS last_call_at,
    MAX(created_at) AS last_sync_at,
    COUNT(*) AS total_calls,
    SUM(CASE WHEN call_date = CURDATE() THEN 1 ELSE 0 END) AS calls_today
FROM calls
GROUP BY COALESCE(NULLIF(user_phone, ''), ''), COALESCE(NULLIF(manager, ''), 'Не указан')
SQL);

    foreach ($callsStmt->fetchAll() as $row) {
        $key = normalizeUserKey($row['user_phone'] ?? '', $row['manager'] ?? '');
        $users[$key] = [
            'id' => $key,
            'manager' => $row['manager'] ?? 'Не указан',
            'user_phone' => $row['user_phone'] ?? '',
            'last_activity' => $row['last_sync_at'] ?: $row['last_call_at'] ?: null,
            'last_sync_at' => $row['last_sync_at'] ?: null,
            'last_call_at' => $row['last_call_at'] ?: null,
            'total_calls' => (int)($row['total_calls'] ?? 0),
            'calls_today' => (int)($row['calls_today'] ?? 0),
            'sync_errors_count' => null,
            'contacts_count' => null,
            'source' => 'calls',
        ];
    }

    $personalStmt = $pdo->query(<<<'SQL'
SELECT
    COALESCE(NULLIF(user_phone, ''), '') AS user_phone,
    COALESCE(NULLIF(manager, ''), 'Не указан') AS manager,
    MAX(updated_at) AS last_personal_contact_at,
    COUNT(*) AS contacts_count
FROM personal_contacts
GROUP BY COALESCE(NULLIF(user_phone, ''), ''), COALESCE(NULLIF(manager, ''), 'Не указан')
SQL);

    foreach ($personalStmt->fetchAll() as $row) {
        $key = normalizeUserKey($row['user_phone'] ?? '', $row['manager'] ?? '');
        if (!isset($users[$key])) {
            $users[$key] = [
                'id' => $key,
                'manager' => $row['manager'] ?? 'Не указан',
                'user_phone' => $row['user_phone'] ?? '',
                'last_activity' => $row['last_personal_contact_at'] ?: null,
                'last_sync_at' => null,
                'last_call_at' => null,
                'total_calls' => 0,
                'calls_today' => 0,
                'sync_errors_count' => null,
                'contacts_count' => (int)($row['contacts_count'] ?? 0),
                'source' => 'personal_contacts',
            ];
            continue;
        }

        $users[$key]['contacts_count'] = (int)($row['contacts_count'] ?? 0);
        if (empty($users[$key]['last_activity']) || (!empty($row['last_personal_contact_at']) && $row['last_personal_contact_at'] > $users[$key]['last_activity'])) {
            $users[$key]['last_activity'] = $row['last_personal_contact_at'];
        }
    }

    $data = array_values($users);
    usort($data, static fn(array $a, array $b): int => strcmp((string)($b['last_activity'] ?? ''), (string)($a['last_activity'] ?? '')));

    sendJson(['status' => 'success', 'data' => $data]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
