<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/android_auth.php';
require_once __DIR__.'/client_directory.php';
require_once __DIR__.'/sales_journal.php';

try {
    $pdo=getPdo();
    $user=currentAndroidUser($pdo);
    if(!$user)sendJson(['status'=>'error','message'=>'Требуется авторизация'],401);
    [$from,$to]=salesJournalPeriod($_GET);
    $identity=androidManagerIdentity($user);

    $stmt=$pdo->prepare('SELECT id_db,call_date,call_time,phone,call_type,duration,manager,client,comment,tag,reminder,reminder_text FROM calls WHERE user_phone=:user_phone AND call_date>=:from AND call_date<=:to ORDER BY call_date DESC,call_time DESC,id_db DESC');
    $stmt->execute([':user_phone'=>$identity['user_phone'],':from'=>$from,':to'=>$to]);
    $calls=enrichAndroidTimelineCalls($stmt->fetchAll());

    ensureEmailTables($pdo);
    $outgoing="(email_messages.direction='outgoing' OR (email_messages.from_email<>'' AND LOWER(email_messages.from_email)=LOWER(email_mailboxes.email)))";
    $emailStmt=$pdo->prepare("SELECT email_messages.id,email_messages.sent_at,email_messages.client_name,email_messages.client_email,email_messages.to_emails,email_messages.subject FROM email_messages LEFT JOIN email_mailboxes ON email_mailboxes.id=email_messages.mailbox_id WHERE email_messages.manager_name=:manager AND email_messages.sent_at>=:from AND email_messages.sent_at<=:to AND $outgoing ORDER BY email_messages.sent_at DESC,email_messages.id DESC");
    $emailStmt->execute([':manager'=>$identity['manager'],':from'=>$from.' 00:00:00',':to'=>$to.' 23:59:59']);

    $salesItems=[];$salesAvailable=true;
    try {
        $clients=salesJournalCommunicationClients($pdo,$user,$identity['manager'],$from,$to);
        $salesItems=loadSalesJournalBatchCached($clients,$from,$to)['items'];
    } catch(Throwable $salesError) {
        $salesAvailable=false;
        error_log('Android Sales Journal unavailable: '.$salesError->getMessage());
    }
    sendJson(['status'=>'success','data'=>['manager'=>$identity['manager'],'calls'=>$calls,'emails'=>$emailStmt->fetchAll(),'sales'=>$salesItems,'sales_available'=>$salesAvailable]]);
} catch(InvalidArgumentException $e) {
    sendJson(['status'=>'error','message'=>$e->getMessage()],400);
} catch(Throwable $e) {
    error_log('Android client timeline unavailable: '.$e->getMessage());
    sendJson(['status'=>'error','message'=>'Не удалось загрузить журнал звонков клиентам'],502);
}

function enrichAndroidTimelineCalls(array $rows): array
{
    try {
        $index=readClientsPhoneIndexCache();
        foreach($rows as &$row){$phone=normalizeClientPhone((string)$row['phone']);if($phone!==''&&isset($index[$phone]))$row['client']=$index[$phone];}
        unset($row);
    } catch(Throwable $e) { error_log('Android timeline client enrichment failed: '.$e->getMessage()); }
    return $rows;
}
