<?php
declare(strict_types=1);
require_once __DIR__.'/sales_journal.php';
try{
    $pdo=getPdo();requireWebUser($pdo);$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)sendJson(['status'=>'error','message'=>'Некорректный ID продажи'],400);
    if(!isSalesJournalDetailAllowed((int)$id))sendJson(['status'=>'error','message'=>'Продажа недоступна в выбранной области'],403);
    // Проверка session allow-list выполняется до чтения общего технического кэша.
    $detail=loadSalesJournalDetailCached((int)$id);
    sendJson(['status'=>'success','data'=>$detail]);
}catch(Throwable $e){error_log('Sales Journal detail unavailable: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить карточку продажи'],502);}
