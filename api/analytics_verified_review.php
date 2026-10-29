<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/analytics_sync.php';
require_once __DIR__.'/analytics_verified_bindings.php';
try{
 $db=getPdo();$actor=requireWebAdmin($db,false);$method=$_SERVER['REQUEST_METHOD'];
 if(!in_array($method,['GET','POST'],true))sendJson(['status'=>'error','message'=>'Метод не разрешён'],405);
 $input=$method==='POST'?readJsonBody():$_GET;$key=taskText($input['signal_key']??null,64);
 $s=$db->prepare('SELECT payload_json FROM analytics_inbox WHERE signal_key=?');$s->execute([$key]);$raw=$s->fetchColumn();
 if(!$raw)throw new DomainException('Исходный сигнал не найден');$event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);analyticsValidateEvent($event);
 if(($event['subject']['kind']??null)!=='normalized_name')throw new DomainException('Выберите исходный SHADOW-сигнал для проверки истории');
 if($method==='POST'&&($input['action']??null)==='revoke'){
  $result=analyticsSaveGroupReview($db,$actor,$event,[],[],$input);
  sendJson(['status'=>'success','review'=>$result,'automation_allowed'=>false]);
 }
 $historyPath='/api/integrations/calltrack/analytics/identity-history?client_key='.rawurlencode($event['subject']['key']);
 if(($input['action']??null)==='history_rows'){
  $after=filter_var($input['after']??0,FILTER_VALIDATE_INT);if($after===false||$after<0)throw new DomainException('Некорректный курсор');
  $snapshot=salesJournalRequest('GET',$historyPath);analyticsReviewHistory($event,$snapshot);
  $progress=$_SESSION['analytics_history_review'][$key]??null;
  if($after===0)$progress=['history_hash'=>$snapshot['history_hash'],'after'=>0,'seen'=>0,'complete'=>false];
  if(!$progress||$progress['history_hash']!==$snapshot['history_hash']||$progress['after']!==$after)throw new DomainException('История изменилась или пропущена страница. Начните просмотр заново.');
  $page=salesJournalRequest('GET',str_replace('identity-history?','identity-history/rows?',$historyPath).'&after='.$after);
  if(!is_array($page['items']??null)||!array_is_list($page['items'])||!array_key_exists('next_after',$page))throw new DomainException('Неполная страница истории');
  $progress['seen']+=count($page['items']);$progress['after']=$page['next_after'];
  $progress['complete']=$page['next_after']===null&&$progress['seen']===$snapshot['sale_count'];
  $_SESSION['analytics_history_review'][$key]=$progress;
  sendJson(['status'=>'success','history'=>$page,'all_history_loaded'=>$progress['complete']]);
 }
 $lock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');
 if(!$lock||!flock($lock,LOCK_SH|LOCK_NB))throw new RuntimeException('Обновление справочника: повторите проверку');
 try{
  $group=analyticsReviewGroup($event,analyticsReviewDirectoryRows(),managerBindingRows($db),clientCatalogRevision());
  $history=salesJournalRequest('GET',$historyPath);analyticsReviewHistory($event,$history);
  $subjectKey=analyticsSubjectKey($event['subject'],$event['run']['scope']);$binding=analyticsBinding($db,$subjectKey);
  if($method==='GET')sendJson(['status'=>'success','group'=>$group,'history'=>$history,'binding'=>$binding,'automation_allowed'=>false]);
  if(($input['action']??null)==='export'){
   if(!$binding)throw new DomainException('Проверка отсутствует');
   $receipt=analyticsBindingAssignable($binding)?analyticsExportReview($binding,$group,$history,time()):analyticsExportReviewRefusal($binding,time());
   sendJson(['status'=>'success','receipt'=>$receipt,'automation_allowed'=>false]);
  }
  if(($input['action']??null)==='confirm'&&((($_SESSION['analytics_history_review'][$key]['complete']??false)!==true)||($_SESSION['analytics_history_review'][$key]['history_hash']??null)!==$history['history_hash']))throw new DomainException('Просмотрите всю текущую историю продаж перед подтверждением');
  $result=analyticsSaveGroupReview($db,$actor,$event,$group,$history,$input);
  sendJson(['status'=>'success','review'=>$result,'automation_allowed'=>false]);
 }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}catch(TaskConflict $e){sendJson(['status'=>'error','message'=>$e->getMessage()],409);
}catch(DomainException|InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('verified_review: '.get_class($e));sendJson(['status'=>'error','message'=>'Проверка недоступна. Привязка не подтверждена.'],503);}
