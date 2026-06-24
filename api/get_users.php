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
    try {
        ensureUserTelemetryTables($pdo);
    } catch (Throwable $e) {
        // Если у пользователя БД нет прав CREATE, список пользователей всё равно строим по существующей таблице calls.
    }
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



    try {
        $reportStmt = $pdo->query('SELECT * FROM app_user_reports');
        foreach ($reportStmt->fetchAll() as $row) {
            $key = normalizeUserKey($row['user_phone'] ?? '', $row['manager'] ?? '');
            if (!isset($users[$key])) {
                $users[$key] = [
                    'id' => $key,
                    'manager' => $row['manager'] ?? 'Не указан',
                    'user_phone' => $row['user_phone'] ?? '',
                    'last_activity' => $row['last_activity'] ?? null,
                    'last_sync_at' => null,
                    'last_call_at' => null,
                    'total_calls' => 0,
                    'calls_today' => 0,
                    'contacts_count' => null,
                    'source' => 'app_user_reports',
                ];
            }
            foreach ($row as $field => $value) {
                if ($field === 'id') continue;
                $users[$key][$field] = $value;
            }
            if (!empty($row['last_activity'])) {
                $users[$key]['last_activity'] = $row['last_activity'];
            }
        }

        $logsStmt = $pdo->query('SELECT user_phone, level, category, message, logged_at FROM app_user_logs ORDER BY logged_at DESC, id DESC LIMIT 5000');
        foreach ($logsStmt->fetchAll() as $row) {
            $key = normalizeUserKey($row['user_phone'] ?? '', '');
            if (!isset($users[$key])) {
                continue;
            }
            $users[$key]['logs'] ??= [];
            if (count($users[$key]['logs']) >= 500) {
                continue;
            }
            $users[$key]['logs'][] = [
                'level' => $row['level'] ?? '',
                'category' => $row['category'] ?? '',
                'message' => $row['message'] ?? '',
                'logged_at' => $row['logged_at'] ?? '',
            ];
        }
    } catch (Throwable $e) {
        // Телеметрия появится после первого отчёта приложения; без неё возвращаем пользователей из calls/personal_contacts.
    }

    $data = array_values($users);
    usort($data, static fn(array $a, array $b): int => strcmp((string)($b['last_activity'] ?? ''), (string)($a['last_activity'] ?? '')));

    sendJson(['status' => 'success', 'data' => $data]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
