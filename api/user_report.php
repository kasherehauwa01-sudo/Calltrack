<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    $data = readJsonBody();
    $userPhone = trim((string)($data['user_phone'] ?? ''));
    if ($userPhone === '') {
        sendJson(['status' => 'error', 'message' => 'Поле user_phone обязательно'], 400);
    }
    if (isUserBlocked($pdo, $userPhone, valueOrNull($data, 'manager'))) {
        sendJson(['status' => 'success', 'blocked' => true, 'commands' => []]);
    }

    $fields = [
        'manager','last_activity','app_version','installed_at','app_updated_at','last_launch_at','launch_count',
        'device_manufacturer','device_model','android_version','api_level','ram_total','storage_free','screen_resolution',
        'device_language','timezone','calls_permission','notifications_permission','contacts_permission','background_permission',
        'battery_optimization_ignored','google_play_services','sync_errors_count','local_db_size','last_error','last_server_response'
    ];
    $columns = ['user_phone'];
    $placeholders = [':user_phone'];
    $updates = [];
    $params = [':user_phone' => $userPhone];
    foreach ($fields as $field) {
        $columns[] = $field;
        $placeholders[] = ':' . $field;
        $updates[] = $field . ' = VALUES(' . $field . ')';
        $params[':' . $field] = valueOrNull($data, $field);
    }
    $sql = 'INSERT INTO app_user_reports (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $logs = $data['logs'] ?? [];
    if (is_array($logs)) {
        $logStmt = $pdo->prepare('INSERT INTO app_user_logs (user_phone, manager, level, category, message, logged_at) VALUES (:user_phone, :manager, :level, :category, :message, :logged_at)');
        foreach (array_slice($logs, -500) as $log) {
            if (!is_array($log)) continue;
            $logStmt->execute([
                ':user_phone' => $userPhone,
                ':manager' => valueOrNull($data, 'manager'),
                ':level' => $log['level'] ?? null,
                ':category' => $log['category'] ?? null,
                ':message' => $log['message'] ?? null,
                ':logged_at' => $log['logged_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    $cmdStmt = $pdo->prepare("SELECT id, command FROM app_user_commands WHERE user_phone = :user_phone AND status = 'pending' ORDER BY id ASC LIMIT 10");
    $cmdStmt->execute([':user_phone' => $userPhone]);
    sendJson(['status' => 'success', 'commands' => $cmdStmt->fetchAll()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
