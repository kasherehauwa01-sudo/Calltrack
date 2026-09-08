<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/web_auth.php';

try {
    $pdo=getPdo();$webUser=requireWebUser($pdo);$scope=webManagerScope($webUser);
    $data = readJsonBody();
    $callId = trim((string)($data['call_id'] ?? ''));
    if ($callId === '') {
        sendJson(['status' => 'error', 'message' => 'Поле call_id обязательно'], 400);
    }

    $map = [
        'date' => ['column' => 'call_date', 'normalizer' => 'normalizeDate'],
        'time' => ['column' => 'call_time', 'normalizer' => 'normalizeTime'],
        'phone' => ['column' => 'phone'],
        'type' => ['column' => 'call_type'],
        'duration' => ['column' => 'duration', 'cast' => 'int'],
        'manager' => ['column' => 'manager'],
        'comment' => ['column' => 'comment'],
        'tag' => ['column' => 'tag'],
        'reminder' => ['column' => 'reminder', 'normalizer' => 'normalizeDateTime'],
        'reminder_text' => ['column' => 'reminder_text'],
        'client' => ['column' => 'client'],
        'user_phone' => ['column' => 'user_phone'],
    ];
    if($scope)$map=array_intersect_key($map,array_flip(['comment','tag','reminder','reminder_text']));

    $set = [];
    $params = [':call_id' => $callId];
    foreach ($map as $jsonKey => $rule) {
        if (!array_key_exists($jsonKey, $data)) {
            continue;
        }
        $param = ':' . $jsonKey;
        $set[] = $rule['column'] . ' = ' . $param;
        $value = valueOrNull($data, $jsonKey);
        if (($rule['cast'] ?? null) === 'int') {
            $value = (int)($data[$jsonKey] ?? 0);
        } elseif (($rule['normalizer'] ?? null) === 'normalizeDateTime') {
            // MariaDB DATETIME не принимает пустую строку.
            // При отсутствии значения передаём NULL.
            $value = empty($data[$jsonKey] ?? null) ? null : normalizeDateTime($data[$jsonKey]);
        } elseif (isset($rule['normalizer'])) {
            $value = $rule['normalizer']($value);
        }
        $params[$param] = $value;
    }

    if (!$set) {
        sendJson(['status' => 'error', 'message' => 'Нет полей для обновления'], 400);
    }

    $scopeSql=$scope?' AND user_phone=:session_user_phone':'';$sql = 'UPDATE calls SET ' . implode(', ', $set) . ' WHERE call_id = :call_id'.$scopeSql;
    if($scope)$params[':session_user_phone']=$scope['user_phone'];
    $existsStmt = $pdo->prepare('SELECT id_db FROM calls WHERE call_id = :call_id'.$scopeSql.' LIMIT 1');
    $existsParams=[':call_id'=>$callId];if($scope)$existsParams[':session_user_phone']=$scope['user_phone'];$existsStmt->execute($existsParams);
    if (!$existsStmt->fetch()) {
        sendJson(['status' => 'error', 'message' => 'Запись с таким call_id не найдена'], 404);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
