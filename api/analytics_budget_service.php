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
