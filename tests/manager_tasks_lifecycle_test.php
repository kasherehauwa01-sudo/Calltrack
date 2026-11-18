<?php
declare(strict_types=1);
require_once __DIR__.'/../api/manager_tasks_service.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function expect(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
function denied(callable $fn,string $class):void{try{$fn();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];$manager=['id'=>3,'role'=>'manager'];$supervisor=['id'=>2,'role'=>'supervisor'];
// Terminal statuses can be changed repeatedly without a result, including after a slot is reused.
$reopened=createManagerTask($pdo,$admin,['client_id'=>'client-reopen','assignee_id'=>3,'title'=>'Reopen test','family'=>'INACTIVITY'])['task'];
updateManagerTask($pdo,$manager,$reopened['id'],['version'=>1,'status'=>'DONE']);
$replacement=createManagerTask($pdo,$admin,['client_id'=>'client-reopen','assignee_id'=>3,'title'=>'New task','family'=>'INACTIVITY'])['task'];
expect($replacement['id']!==$reopened['id'],'completion frees the slot');
updateManagerTask($pdo,$manager,$reopened['id'],['version'=>2,'status'=>'IN_PROGRESS']);
updateManagerTask($pdo,$admin,$reopened['id'],['version'=>3,'status'=>'CANCELLED','result'=>'']);
updateManagerTask($pdo,$manager,$reopened['id'],['version'=>4,'status'=>'OPEN']);
denied(fn()=>deleteManagerTask($pdo,$manager,$reopened['id'],5),DomainException::class);
denied(fn()=>deleteManagerTask($pdo,$admin,$reopened['id'],4),TaskConflict::class);
deleteManagerTask($pdo,$admin,$replacement['id'],1);
expect(findOpenTaskInSlot($pdo,taskSlot('client-reopen','INACTIVITY'))['id']===$reopened['id'],'reopened task still occupies the group');
deleteManagerTask($pdo,$supervisor,$reopened['id'],5);
expect(findOpenTaskInSlot($pdo,taskSlot('client-reopen','INACTIVITY'))===null,'deletion frees the reopened slot');
denied(fn()=>updateManagerTask($pdo,$admin,$reopened['id'],['version'=>6,'status'=>'DONE']),DomainException::class);
$deleted=$pdo->prepare('SELECT * FROM manager_tasks WHERE id=?');$deleted->execute([$reopened['id']]);
expect(!taskCanRead($admin,$deleted->fetch()),'deleted task is hidden');
$history=$pdo->prepare("SELECT actor_id FROM manager_task_events WHERE task_id=? AND event_type='DELETED'");$history->execute([$reopened['id']]);
expect((int)$history->fetchColumn()===2,'deletion is audited with the supervisor as actor');
echo "PASS: optional result, repeated status changes, slot reuse, deletion and permissions\n";
