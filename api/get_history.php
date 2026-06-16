<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $phone = trim((string)($_GET['phone'] ?? ''));
    $userPhone = trim((string)($_GET['user_phone'] ?? ''));
    if ($phone === '') {
        sendJson(['status' => 'error', 'message' => 'Параметр phone обязателен'], 400);
    }

    $where = ['phone = :phone'];
    $params = [':phone' => $phone];
    if ($userPhone !== '') {
        $where[] = 'user_phone = :user_phone';
        $params[':user_phone'] = $userPhone;
    }

    $sql = 'SELECT * FROM calls WHERE ' . implode(' AND ', $where) . ' ORDER BY call_date DESC, call_time DESC, id_db DESC';
    $stmt = getPdo()->prepare($sql);
    $stmt->execute($params);
    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
