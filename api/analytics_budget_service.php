<?php
declare(strict_types=1);
class AutomationBudgetBlocked extends RuntimeException {}
function analyticsBudget(PDO $pdo,bool $lock=false): array {
 $s=$pdo->query('SELECT * FROM analytics_task_budget WHERE id=1'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
 $row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new AutomationBudgetBlocked('BUDGET_MISSING');return $row;
}
function analyticsBudgetReason(array $row): ?string {
 if((int)$row['approved']!==1||$row['max_tasks']===null)return 'BUDGET_NOT_APPROVED';
 if((int)$row['used_tasks']>=(int)$row['max_tasks'])return 'BUDGET_EXHAUSTED';return null;
}
function reserveAnalyticsBudget(PDO $pdo):void {
 if(!$pdo->inTransaction())throw new AutomationBudgetBlocked('BUDGET_TRANSACTION_REQUIRED');
 $row=analyticsBudget($pdo,true);$reason=analyticsBudgetReason($row);if($reason)throw new AutomationBudgetBlocked($reason);
 $s=$pdo->prepare('UPDATE analytics_task_budget SET used_tasks=used_tasks+1 WHERE id=1 AND approved=1 AND max_tasks IS NOT NULL AND used_tasks<max_tasks');$s->execute();
 if($s->rowCount()!==1)throw new AutomationBudgetBlocked('BUDGET_RESERVATION_FAILED');
}
function saveAnalyticsBudget(PDO $pdo,array $actor,array $input):void {
 if(($actor['role']??'')!=='admin')throw new DomainException('Admin only');
 if(!is_int($input['revision']??null)||!is_bool($input['approved']??null)||!is_int($input['max_tasks']??null)||$input['max_tasks']<0)throw new InvalidArgumentException('Explicit revision/approval/nonnegative limit required');
 $pdo->beginTransaction();try{
  $row=analyticsBudget($pdo,true);if((int)$row['revision']!==$input['revision'])throw new TaskConflict('Budget changed');
  if($input['max_tasks']<(int)$row['used_tasks'])throw new InvalidArgumentException('Limit cannot be less than consumed budget');
  $pdo->prepare('UPDATE analytics_task_budget SET approved=?,max_tasks=?,revision=revision+1 WHERE id=1')->execute([$input['approved']?1:0,$input['max_tasks']]);
  $pdo->prepare('INSERT INTO analytics_task_budget_events(actor_id,revision,data_json) VALUES(?,?,?)')->execute([(int)$actor['id'],$input['revision']+1,json_encode($input,JSON_THROW_ON_ERROR)]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** One pilot-wide bucket, all managers; lifetime lock is always acquired first. */
function analyticsDailyBudgetPolicy(PDO $pdo): array {
 try {
  $row=$pdo->query('SELECT * FROM analytics_daily_budget_policy WHERE id=1'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
  if(!$row||$row['policy_version']!=='aviator-churn-pilot/1'||$row['timezone']!=='Europe/Moscow'||(string)$row['max_tasks']!=='5')throw new AutomationBudgetBlocked('DAILY_BUDGET_INVALID');
  return $row;
 }catch(AutomationBudgetBlocked $e){throw $e;}catch(Throwable $e){throw new AutomationBudgetBlocked('DAILY_BUDGET_UNAVAILABLE',0,$e);}
}
function analyticsBusinessDate(?DateTimeImmutable $now=null): string {
 return ($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
}
function analyticsDailyBudgetReason(PDO $pdo,?DateTimeImmutable $now=null): ?string {
 analyticsDailyBudgetPolicy($pdo);
 $day=analyticsBusinessDate($now);
 try {
  $s=$pdo->prepare('SELECT used_tasks FROM analytics_daily_budget WHERE policy_id=1 AND business_date=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$day]);$used=$s->fetchColumn();
  $ledger=$pdo->prepare('SELECT COUNT(*) FROM analytics_daily_budget_reservations WHERE policy_id=1 AND business_date=?');$ledger->execute([$day]);$count=(int)$ledger->fetchColumn();
  if($used===false)return $count===0?null:'DAILY_BUDGET_INVALID';
  if(!ctype_digit((string)$used)||(int)$used>5||(int)$used!==$count) return 'DAILY_BUDGET_INVALID';
  return (int)$used>=5?'DAILY_BUDGET_EXHAUSTED':null;
 }catch(Throwable $e){throw new AutomationBudgetBlocked('DAILY_BUDGET_UNAVAILABLE',0,$e);}
}
function reserveAnalyticsDailyBudget(PDO $pdo,?DateTimeImmutable $now=null,?string $signalKey=null):void {
 if(!$pdo->inTransaction())throw new AutomationBudgetBlocked('BUDGET_TRANSACTION_REQUIRED');
 analyticsDailyBudgetPolicy($pdo);
 $reason=analyticsDailyBudgetReason($pdo,$now);if($reason)throw new AutomationBudgetBlocked($reason);
 $day=analyticsBusinessDate($now);
 try {
  $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
   ?'INSERT INTO analytics_daily_budget(policy_id,business_date,used_tasks) VALUES(1,?,0) ON DUPLICATE KEY UPDATE policy_id=policy_id'
   :'INSERT INTO analytics_daily_budget(policy_id,business_date,used_tasks) VALUES(1,?,0) ON CONFLICT(policy_id,business_date) DO NOTHING';
  $pdo->prepare($sql)->execute([$day]);
  $s=$pdo->prepare('UPDATE analytics_daily_budget SET used_tasks=used_tasks+1 WHERE policy_id=1 AND business_date=? AND used_tasks>=0 AND used_tasks<5');$s->execute([$day]);
  if($s->rowCount()!==1)throw new AutomationBudgetBlocked('DAILY_BUDGET_EXHAUSTED');
  $pdo->prepare('INSERT INTO analytics_daily_budget_reservations(reservation_key,policy_id,business_date) VALUES(?,1,?)')->execute([$signalKey??bin2hex(random_bytes(32)),$day]);
 }catch(AutomationBudgetBlocked $e){throw $e;}catch(Throwable $e){throw new AutomationBudgetBlocked('DAILY_BUDGET_UNAVAILABLE',0,$e);}
}
function analyticsPilotDue(DateTimeImmutable $created,int $days):string {
 return $created->setTimezone(new DateTimeZone('Europe/Moscow'))->modify('+'.$days.' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
