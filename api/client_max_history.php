<?php
declare(strict_types=1);
require_once __DIR__.'/max_message_history.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sendJson(['status'=>'error','message'=>'POST required'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$input=readJsonBody();
    $today=(new DateTimeImmutable('now',new DateTimeZone(MAX_HISTORY_TIMEZONE)))->format('Y-m-d');
    $from=(string)($input['from'] ?? '2000-01-01');$to=(string)($input['to'] ?? $today);
    maxHistoryIntervals($from,$to); // Validate even when no phone/identity can be resolved.
    // Client-supplied manager, user_phone, client name and timezone never authorize a MAX query.
    $context=maxHistoryContext($pdo,$user,$input);
    $phones=maxHistoryPhones($context);
    $owner=maxHistoryOwner($pdo,$user,$context);
    sendJson(['status'=>'success','data'=>maxHistoryDaily($pdo,$owner,$phones,$from,$to)]);
} catch (ClientCatalogAccessDenied $error) {
    sendJson(['status'=>'error','message'=>$error->getMessage()],403);
} catch (InvalidArgumentException $error) {
    sendJson(['status'=>'error','message'=>'Некорректный запрос истории MAX'],400);
} catch (Throwable $error) {
    sendJson(['status'=>'error','message'=>'История MAX временно недоступна'],503);
}
