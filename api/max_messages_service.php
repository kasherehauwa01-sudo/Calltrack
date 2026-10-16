<?php
declare(strict_types=1);

/** Pure validation plus injectable auth/persistence boundary, also used by contract tests. */
function maxMessageString(array $row, string $key, int $limit, bool $required = false): string
{
    $value = $row[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException('Invalid message metadata');
    }
    $value = trim($value);
    if (preg_match_all('/./us', $value) > $limit || ($required && $value === '')) {
        throw new InvalidArgumentException('Invalid message metadata');
    }
    return $value;
}

function validateMaxMessages(array $payload): array
{
    if (array_diff(array_keys($payload), ['messages'])) throw new InvalidArgumentException('Unsupported payload fields');
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || !array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
        throw new InvalidArgumentException('Expected 1 to 100 messages');
    }
    $result = [];
    $allowed = ['source_event_id', 'direction', 'contact_name', 'phone', 'max_user_id', 'shortcut_id', 'occurred_at', 'is_group_conversation', 'manager', 'user_phone'];
    foreach ($messages as $row) {
        if (!is_array($row) || array_diff(array_keys($row), $allowed)) {
            throw new InvalidArgumentException('Unsupported message fields');
        }
        // No text field is accepted, including legacy message_text. Omitted/false group flag only.
        if (array_key_exists('is_group_conversation', $row) && $row['is_group_conversation'] !== false && $row['is_group_conversation'] !== 0) {
            throw new InvalidArgumentException('Group messages are not supported');
        }
        $event = maxMessageString($row, 'source_event_id', 190, true);
        if (!preg_match('/^[A-Za-z0-9:._-]+$/D', $event)) throw new InvalidArgumentException('Invalid source event ID');
        $direction = $row['direction'] ?? null;
        if (!in_array($direction, ['incoming', 'outgoing'], true)) throw new InvalidArgumentException('Invalid direction');
        $timestamp = $row['occurred_at'] ?? null;
        if (!is_int($timestamp) || $timestamp < 946684800000 || $timestamp > min(4102444800000, (time() + 86400) * 1000)) {
            throw new InvalidArgumentException('Invalid occurred_at milliseconds');
        }
        $result[] = [
            'source_event_id' => $event, 'direction' => $direction,
            'contact_name' => maxMessageString($row, 'contact_name', 255, true),
            'phone' => maxMessageString($row, 'phone', 30),
            'max_user_id' => maxMessageString($row, 'max_user_id', 190),
            'shortcut_id' => maxMessageString($row, 'shortcut_id', 190),
            'occurred_at' => gmdate('Y-m-d H:i:s', intdiv($timestamp, 1000)),
        ];
    }
    return $result;
}

function storeMaxMessages(PDO $pdo, array $messages, array $identity): array
{
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO max_messages
            (source_event_id,direction,contact_name,phone,max_user_id,shortcut_id,occurred_at,manager,user_phone)
            VALUES (:source_event_id,:direction,:contact_name,:phone,:max_user_id,:shortcut_id,:occurred_at,:manager,:user_phone)
            ON DUPLICATE KEY UPDATE source_event_id=source_event_id');
        $owner = $pdo->prepare('SELECT user_phone FROM max_messages WHERE source_event_id=:event');
        $accepted = [];
        foreach ($messages as $row) {
            $row['manager'] = $identity['manager'];
            $row['user_phone'] = $identity['user_phone'];
            foreach (['phone', 'max_user_id', 'shortcut_id'] as $field) if ($row[$field] === '') $row[$field] = null;
            $insert->execute($row);
            $owner->execute(['event' => $row['source_event_id']]);
            if ((string)$owner->fetchColumn() !== (string)$identity['user_phone']) throw new RuntimeException('Event ownership conflict');
            $accepted[] = $row['source_event_id'];
        }
        $pdo->commit();
        return array_values(array_unique($accepted));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function handleMaxMessages(string $method, bool $https, string $body, callable $authenticate, callable $identityFor, callable $persist): array
{
    if ($method !== 'POST') return [405, ['status' => 'error', 'message' => 'POST required']];
    if (!$https) return [400, ['status' => 'error', 'message' => 'HTTPS required']];
    try {
        $user = $authenticate();
        if (!$user) return [401, ['status' => 'error', 'message' => 'Android authentication required']];
        if (strlen($body) > 262144) return [413, ['status' => 'error', 'message' => 'Request too large']];
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new InvalidArgumentException('Expected JSON object');
        $messages = validateMaxMessages($payload);
        $identity = $identityFor($user);
        $accepted = $persist($messages, $identity);
        return [200, ['status' => 'success', 'accepted' => $accepted]];
    } catch (JsonException | InvalidArgumentException $error) {
        return [400, ['status' => 'error', 'message' => 'Invalid MAX message payload']];
    } catch (Throwable $error) {
        // No SQL errors, payload or PII in response or application logs.
        return [500, ['status' => 'error', 'message' => 'MAX message sync unavailable']];
    }
}
