<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo = getPdo();

    $data = readJsonBody();
    $idDb = valueOrNull($data, 'id_db');
    if ($idDb !== null) {
        $existsStmt = $pdo->prepare('SELECT id FROM personal_contacts WHERE id = :id LIMIT 1');
        $existsStmt->execute([':id' => (int)$idDb]);
        if (!$existsStmt->fetch()) {
            sendJson(['status' => 'error', 'message' => 'Запись personal_contacts не найдена'], 404);
        }

        $personalFlag = (int)($data['personal_flag'] ?? 0) === 1 ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE personal_contacts SET personal_flag = :personal_flag, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':personal_flag' => $personalFlag, ':id' => (int)$idDb]);
        sendJson(['status' => 'success']);
    }

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
