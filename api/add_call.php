<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $data = readJsonBody();
    $callId = trim((string)($data['call_id'] ?? ''));
    if ($callId === '') {
        sendJson(['status' => 'error', 'message' => 'Поле call_id обязательно'], 400);
    }

    // MariaDB DATETIME не принимает пустую строку.
    // При отсутствии значения передаём NULL.
    $reminder = empty($data['reminder'] ?? null) ? null : normalizeDateTime($data['reminder']);

    $pdo = getPdo();
    if (isUserBlocked($pdo, valueOrNull($data, 'user_phone'), valueOrNull($data, 'manager'))) {
        sendJson(['status' => 'success', 'skipped' => true, 'message' => 'Пользователь заблокирован']);
    }

    $params = [
        ':call_date' => normalizeDate(valueOrNull($data, 'date')),
        ':call_time' => normalizeTime(valueOrNull($data, 'time')),
        ':phone' => valueOrNull($data, 'phone'),
        ':call_type' => valueOrNull($data, 'type'),
        ':duration' => (int)($data['duration'] ?? 0),
        ':manager' => valueOrNull($data, 'manager'),
        ':comment' => valueOrNull($data, 'comment'),
        ':tag' => valueOrNull($data, 'tag'),
        ':reminder' => $reminder,
        ':reminder_text' => valueOrNull($data, 'reminder_text'),
        ':client' => valueOrNull($data, 'client'),
        ':call_id' => $callId,
        ':user_phone' => valueOrNull($data, 'user_phone'),
    ];

    $sql = <<<'SQL'
INSERT INTO calls (
    call_date, call_time, phone, call_type, duration, manager, comment, tag,
    reminder, reminder_text, client, call_id, user_phone
) VALUES (
    :call_date, :call_time, :phone, :call_type, :duration, :manager, :comment, :tag,
    :reminder, :reminder_text, :client, :call_id, :user_phone
)
ON DUPLICATE KEY UPDATE
    call_date = VALUES(call_date),
    call_time = VALUES(call_time),
    phone = VALUES(phone),
    call_type = VALUES(call_type),
    duration = VALUES(duration),
    manager = VALUES(manager),
    comment = VALUES(comment),
    tag = VALUES(tag),
    reminder = VALUES(reminder),
    reminder_text = VALUES(reminder_text),
    client = VALUES(client),
    user_phone = VALUES(user_phone)
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
