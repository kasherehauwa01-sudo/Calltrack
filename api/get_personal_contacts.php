<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensurePersonalContactsTable($pdo);

    $userPhone = valueOrNull($_GET, 'user_phone');
    if ($userPhone === null) {
        sendJson(['status' => 'error', 'message' => 'Поле user_phone обязательно'], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT contact_phone, personal_flag FROM personal_contacts WHERE user_phone = :user_phone ORDER BY updated_at DESC'
    );
    $stmt->execute([':user_phone' => $userPhone]);

    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
