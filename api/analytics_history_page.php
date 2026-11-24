<?php
declare(strict_types=1);
/** Validate upstream history page before mutating reviewer progress. */
function analyticsHistoryAdvancePage(array $snapshot,array $page,array $progress,int $after):array {
 if(!is_array($page['items']??null)||!array_is_list($page['items'])||!array_key_exists('next_after',$page))throw new DomainException('Неполная страница истории');
 $hash=$snapshot['history_hash']??null;
 if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_string($page['history_hash']??null)||!hash_equals($hash,$page['history_hash']))throw new DomainException('История изменилась. Начните просмотр заново.');
 if(!is_int($snapshot['sale_count']??null)||!is_int($page['sale_count']??null)||$snapshot['sale_count']!==$page['sale_count'])throw new DomainException('Изменилось количество продаж');
 foreach(['version','normalization_version','department','client_key'] as $field){
  if(!array_key_exists($field,$snapshot)||!array_key_exists($field,$page)||$snapshot[$field]!==$page[$field])throw new DomainException('Подменён идентификатор истории');
 }
 if(!is_int($progress['seen']??null)||!is_int($progress['after']??null)||$progress['after']!==$after||($progress['history_hash']??null)!==$hash||($progress['complete']??false)===true)throw new DomainException('Неверное состояние просмотра истории');
 $previous=$after;
 foreach($page['items'] as $sale){
  if(!is_array($sale)||!is_int($sale['sale_id']??null)||$sale['sale_id']<=$previous||($sale['department']??null)!=='Авиаторов')throw new DomainException('Некорректный порядок истории');
  $previous=$sale['sale_id'];
 }
 $next=$page['next_after'];
 if($next!==null&&(!is_int($next)||!$page['items']||$next!==$previous))throw new DomainException('Некорректный курсор истории');
 if($next===null&&!$page['items']&&$progress['seen']<$snapshot['sale_count'])throw new DomainException('Пустая незавершённая история');
 $seen=$progress['seen']+count($page['items']);
 if($seen>$snapshot['sale_count'])throw new DomainException('Повторяющиеся строки истории');
 if($next!==null&&$seen===$snapshot['sale_count'])throw new DomainException('Недостоверный курсор истории');
 if($next===null&&$seen!==$snapshot['sale_count'])throw new DomainException('Неполная история');
 return ['history_hash'=>$hash,'after'=>$next,'seen'=>$seen,'complete'=>$next===null];
}
