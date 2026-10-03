<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/android_auth.php';

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
    $androidUser=optionalAndroidUser($pdo);if($androidUser){$identity=androidManagerIdentity($androidUser);$data['user_phone']=$identity['user_phone'];$data['manager']=$identity['manager'];}
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
        ':source' => ($data['source'] ?? 'phone') === 'max' ? 'max' : 'phone',
        ':source_event_id' => valueOrNull($data, 'source_event_id'),
        ':contact_name' => valueOrNull($data, 'contact_name'),
        ':direction' => valueOrNull($data, 'direction'),
        ':status' => valueOrNull($data, 'status'),
        ':started_at' => empty($data['started_at']) ? null : normalizeDateTime($data['started_at']),
        ':answered_at' => empty($data['answered_at']) ? null : normalizeDateTime($data['answered_at']),
        ':ended_at' => empty($data['ended_at']) ? null : normalizeDateTime($data['ended_at']),
        ':ringing_duration_seconds' => isset($data['ringing_duration_seconds']) ? (int)$data['ringing_duration_seconds'] : null,
        ':is_video' => !empty($data['is_video']) ? 1 : 0,
        ':contact_resolution_status' => valueOrNull($data, 'contact_resolution_status'),
    ];

    $sql = <<<'SQL'
INSERT INTO calls (
    call_date, call_time, phone, call_type, duration, manager, comment, tag,
    reminder, reminder_text, client, call_id, user_phone, source, source_event_id,
    contact_name, direction, status, started_at, answered_at, ended_at,
    ringing_duration_seconds, is_video, contact_resolution_status
) VALUES (
    :call_date, :call_time, :phone, :call_type, :duration, :manager, :comment, :tag,
    :reminder, :reminder_text, :client, :call_id, :user_phone, :source, :source_event_id,
    :contact_name, :direction, :status, :started_at, :answered_at, :ended_at,
    :ringing_duration_seconds, :is_video, :contact_resolution_status
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
    user_phone = VALUES(user_phone),
    source = VALUES(source), source_event_id = VALUES(source_event_id),
    contact_name = VALUES(contact_name), direction = VALUES(direction), status = VALUES(status),
    started_at = VALUES(started_at), answered_at = VALUES(answered_at), ended_at = VALUES(ended_at),
    ringing_duration_seconds = VALUES(ringing_duration_seconds), is_video = VALUES(is_video),
    contact_resolution_status = VALUES(contact_resolution_status)
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
