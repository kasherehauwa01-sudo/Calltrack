<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();
    ensurePersonalContactsTable($pdo);

    $data = readJsonBody();
    $userPhone = valueOrNull($data, 'user_phone');
    $contactPhone = valueOrNull($data, 'contact_phone');
    if ($userPhone === null || $contactPhone === null) {
        sendJson(['status' => 'error', 'message' => 'Поля user_phone и contact_phone обязательны'], 400);
    }

    $personalFlag = (int)($data['personal_flag'] ?? 0) === 1 ? 1 : 0;
    $params = [
        ':user_phone' => $userPhone,
        ':manager' => valueOrNull($data, 'manager'),
        ':contact_phone' => $contactPhone,
        ':personal_flag' => $personalFlag,
    ];

    $sql = <<<'SQL'
INSERT INTO personal_contacts (user_phone, manager, contact_phone, personal_flag)
VALUES (:user_phone, :manager, :contact_phone, :personal_flag)
ON DUPLICATE KEY UPDATE
    manager = VALUES(manager),
    personal_flag = VALUES(personal_flag),
    updated_at = CURRENT_TIMESTAMP
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
