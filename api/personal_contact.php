<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/android_auth.php';
require_once __DIR__ . '/client_directory.php';
require_once __DIR__ . '/personal_contacts_filter.php';

function updatePersonalPhoneAliases(PDO $pdo, string $owner, string $phone, int $flag): void
{
    $normalized=normalizeClientPhone($phone);
    if($normalized==='')return;
    $sql='UPDATE personal_contacts SET personal_flag=:flag, updated_at=CURRENT_TIMESTAMP WHERE user_phone=:owner AND '.personalPhoneSql('contact_phone').'=:phone';
    $pdo->prepare($sql)->execute([':flag'=>$flag,':owner'=>$owner,':phone'=>$normalized]);
}


try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') sendJson(['status'=>'error','message'=>'Метод не поддерживается'],405);
    $pdo = getPdo();

    $data = readJsonBody();
    $androidUser=optionalAndroidUser($pdo);
    if($androidUser){
        $identity=androidManagerIdentity($androidUser);$data['user_phone']=$identity['user_phone'];$data['manager']=$identity['manager'];
    } else {
        $webUser=requireWebUser($pdo);
        if (isset($data['call_id'])) {
            require_once __DIR__.'/client_history_context.php';
            $context=clientHistoryContext($pdo,$webUser,$data);
            if ($context['found_in_1c'] || $context['phone']==='' || $context['user_phone']==='') {
                sendJson(['status'=>'error','message'=>'Пометка доступна для неопределённого телефонного контакта'],400);
            }
            // Scope comes exclusively from the authorized persisted call.
            $data=['user_phone'=>$context['user_phone'],'manager'=>$context['manager'],
                'contact_phone'=>$context['phone'],'personal_flag'=>1];
        } elseif ($webUser['role']!=='admin') {
            sendJson(['status'=>'error','message'=>'Доступ разрешён только администратору'],403);
        }
    }
    $idDb = valueOrNull($data, 'id_db');
    if ($idDb !== null) {
        $existsStmt = $pdo->prepare('SELECT id,user_phone,contact_phone FROM personal_contacts WHERE id = :id'.($androidUser?' AND user_phone=:user_phone':'').' LIMIT 1');
        $existsParams=[':id'=>(int)$idDb];if($androidUser)$existsParams[':user_phone']=$data['user_phone'];$existsStmt->execute($existsParams);
        if (!($existing=$existsStmt->fetch())) {
            sendJson(['status' => 'error', 'message' => 'Запись personal_contacts не найдена'], 404);
        }

        $personalFlag = (int)($data['personal_flag'] ?? 0) === 1 ? 1 : 0;
        $pdo->beginTransaction();
        updatePersonalPhoneAliases($pdo,(string)$existing['user_phone'],(string)$existing['contact_phone'],$personalFlag);
        $stmt = $pdo->prepare('UPDATE personal_contacts SET personal_flag = :personal_flag, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':personal_flag' => $personalFlag, ':id' => (int)$idDb]);
        $pdo->commit();
        sendJson(['status' => 'success']);
    }

    $userPhone = valueOrNull($data, 'user_phone');
    $contactPhone = valueOrNull($data, 'contact_phone');
    if ($userPhone === null || $contactPhone === null) {
        sendJson(['status' => 'error', 'message' => 'Поля user_phone и contact_phone обязательны'], 400);
    }

    if (isUserBlocked($pdo, $userPhone, valueOrNull($data, 'manager'))) {
        sendJson(['status' => 'success', 'skipped' => true, 'message' => 'Пользователь заблокирован']);
    }

    $normalized=normalizeClientPhone($contactPhone);
    if($normalized!=='')$contactPhone=$normalized;
    $personalFlag = (int)($data['personal_flag'] ?? 0) === 1 ? 1 : 0;
    $params = [
        ':user_phone' => $userPhone,
        ':manager' => valueOrNull($data, 'manager'),
        ':contact_phone' => $contactPhone,
        ':personal_flag' => $personalFlag,
    ];

    $sql = <<<'SQL'
INSERT INTO personal_contacts (user_phone, manager, contact_phone, personal_flag, updated_at)
VALUES (:user_phone, :manager, :contact_phone, :personal_flag, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
    manager = VALUES(manager),
    personal_flag = VALUES(personal_flag),
    updated_at = CURRENT_TIMESTAMP
SQL;
    $pdo->beginTransaction();
    updatePersonalPhoneAliases($pdo,$userPhone,$contactPhone,$personalFlag);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pdo->commit();

    sendJson(['status' => 'success']);
} catch (Throwable $e) {
    if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack();
    error_log('Personal contact: '.$e->getMessage());
    sendJson(['status'=>'error','message'=>'Не удалось сохранить личный контакт'],500);
}
