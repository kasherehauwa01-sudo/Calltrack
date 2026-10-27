<?php
declare(strict_types=1);
require_once __DIR__.'/web_auth.php';
class TaskConflict extends RuntimeException {}
require_once __DIR__.'/analytics_budget_service.php';
function taskUuid(): string { $h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-'.dechex((hexdec($h[16])&3)|8).substr($h,17,3).'-'.substr($h,20); }
function taskSlot(string $clientId,string $family): string { return hash('sha256',json_encode(['clientsvr',$clientId,$family],JSON_THROW_ON_ERROR)); }
function taskCanRead(array $actor,array $task): bool { return in_array($actor['role']??'',['admin','supervisor'],true)||(($actor['role']??'')==='manager'&&(int)$task['assignee_id']===(int)$actor['id']); }
function taskAssertWrite(array $actor,array $task): void { if(!taskCanRead($actor,$task)||($actor['role']??'')==='supervisor')throw new DomainException('Недостаточно прав'); }
function taskEvent(PDO $pdo,string $id,?int $actor,string $type,array $data): void { $pdo->prepare('INSERT INTO manager_task_events(task_id,actor_id,event_type,data_json) VALUES(?,?,?,?)')->execute([$id,$actor,$type,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]); }
function taskAssignee(PDO $pdo,int $id): void {
    $s=$pdo->prepare("SELECT id FROM web_users WHERE id=? AND role='manager' AND is_active=1".($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$id]);
    if(!$s->fetchColumn())throw new InvalidArgumentException('Назначение возможно только активному менеджеру');
}
function taskText(mixed $value,int $max,bool $required=true): string {
    if(!is_string($value))throw new InvalidArgumentException('Ожидается текст');$value=trim($value);
    if(($required&&$value==='')||mb_strlen($value)>$max)throw new InvalidArgumentException('Некорректная длина текста');return $value;
}
function taskDue(mixed $value): ?string {
    if($value===null||$value==='')return null;
    if(!is_string($value))throw new InvalidArgumentException('Некорректный срок');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('Срок должен быть UTC YYYY-MM-DD HH:MM:SS');return $value;
}
/** Automatic caller supplies trusted gate result; API cannot set source/evidence. */
function createManagerTask(PDO $pdo,array $actor,array $input,?array $automatic=null): array {
    if($automatic===null&&($actor['role']??'')!=='admin')throw new DomainException('Создание и назначение задач доступно администратору');
    $client=taskText($input['client_id']??null,255);$title=taskText($input['title']??null,255);$description=taskText($input['description']??'',10000,false);
    $priority=$input['priority']??'NORMAL';if(!in_array($priority,['LOW','NORMAL','HIGH','URGENT'],true))throw new InvalidArgumentException('Некорректный приоритет');
    $family=$input['family']??'MANUAL';if(!in_array($family,['MANUAL','INACTIVITY'],true))throw new InvalidArgumentException('Некорректная категория');
    $due=taskDue($input['due_at']??null);$assignee=$input['assignee_id']??null;if(!is_int($assignee)||$assignee<=0)throw new InvalidArgumentException('Укажите менеджера');
    if($automatic!==null&&(($automatic['allowed']??false)!==true||empty($automatic['signal_key'])))throw new DomainException('Автоматизация не разрешена');
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try {
        if($automatic!==null){
            // Defense in depth: a direct internal caller cannot bypass persisted revocation.
            require_once __DIR__.'/analytics_task_policy.php';
            $policy=analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo,true));
            $signal=$automatic['evidence']['signal']??[];
            if(($policy['policy_approved']??false)!==true||!in_array('CHURN_RISK',$policy['enabled_types'],true)
                ||($signal['signal_type']??null)!=='CHURN_RISK'||($signal['mode']??null)!=='AUTOMATION_APPROVED'
                ||($signal['automation_allowed']??false)!==true||($signal['status']??null)!=='ACTIVE'
                ||($signal['subject']['kind']??null)!=='external_id'||($signal['subject']['key']??null)!==$client
                ||($signal['assignment']['client_id']??null)!==$client||($signal['assignment']['manager_id']??null)!==$assignee){
                throw new DomainException('Persisted CHURN policy and approved assignment required');
            }
        }
        taskAssignee($pdo,$assignee);
        if($automatic!==null){$s=$pdo->prepare('SELECT * FROM manager_tasks WHERE source_signal_key=?');$s->execute([$automatic['signal_key']]);if($existing=$s->fetch(PDO::FETCH_ASSOC)){if($own)$pdo->commit();return ['task'=>$existing,'existing'=>true];}}
        if($family==='INACTIVITY'){
            $s=$pdo->prepare('SELECT t.* FROM analytics_open_task_slots s JOIN manager_tasks t ON t.id=s.task_id WHERE s.slot_key=?');$s->execute([taskSlot($client,$family)]);
            if($existing=$s->fetch(PDO::FETCH_ASSOC)){if($own)$pdo->commit();return ['task'=>$existing,'existing'=>true];}
        }
        if($automatic!==null){
            reserveAnalyticsBudget($pdo);
            analyticsDailyBudgetPolicy($pdo);
            $createdAt=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            reserveAnalyticsDailyBudget($pdo,$createdAt,$automatic['signal_key']);
            if(($signal['generator_version']??null)==='inactivity/aviator-churn-pilot/1'){
                if(($policy['due_days_by_type']['CHURN_RISK']??null)!==3)throw new DomainException('Pilot requires three calendar days');
                $due=analyticsPilotDue($createdAt,3);
            }
        }
        $id=taskUuid();$evidence=$automatic?json_encode($automatic['evidence'],JSON_THROW_ON_ERROR):null;
        $pdo->prepare('INSERT INTO manager_tasks(id,client_id,assignee_id,title,description,priority,due_at,result,family,source_signal_key,evidence_json,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$client,$assignee,$title,$description,$priority,$due,'',$family,$automatic['signal_key']??null,$evidence,$automatic?null:(int)$actor['id'],isset($createdAt)?$createdAt->format('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s')]);
        if($family==='INACTIVITY')$pdo->prepare('INSERT INTO analytics_open_task_slots(slot_key,task_id) VALUES(?,?)')->execute([taskSlot($client,$family),$id]);
        taskEvent($pdo,$id,$automatic?null:(int)$actor['id'],'CREATED',['assignee_id'=>$assignee,'priority'=>$priority,'source'=>$automatic?'ANALYTICS':'MANUAL']);
        $s=$pdo->prepare('SELECT * FROM manager_tasks WHERE id=?');$s->execute([$id]);$task=$s->fetch(PDO::FETCH_ASSOC);
        if($own)$pdo->commit();return ['task'=>$task,'existing'=>false];
    } catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function updateManagerTask(PDO $pdo,array $actor,string $id,array $input): array {
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM manager_tasks WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$id]);$task=$s->fetch(PDO::FETCH_ASSOC);
        if(!$task||!taskCanRead($actor,$task))throw new DomainException('Задача недоступна');taskAssertWrite($actor,$task);
        if(!is_int($input['version']??null)||(int)$task['version']!==$input['version'])throw new TaskConflict('Задача изменилась. Обновите карточку.');
        if(in_array($task['status'],['DONE','CANCELLED'],true))throw new TaskConflict('Завершённую задачу нельзя изменять или автоматически открывать повторно');
        $status=$input['status']??$task['status'];
        if(!in_array($status,['OPEN','IN_PROGRESS','DONE','CANCELLED'],true))throw new InvalidArgumentException('Некорректный статус');
        if($status==='CANCELLED'&&$actor['role']!=='admin')throw new DomainException('Отмена доступна администратору');
        $result=taskText($input['result']??$task['result'],10000,false);
        if(in_array($status,['DONE','CANCELLED'],true)&&$result==='')throw new InvalidArgumentException('Укажите результат или причину отмены');
        $assignee=(int)$task['assignee_id'];$priority=$task['priority'];$due=$task['due_at'];
        if($actor['role']!=='admin'&&(isset($input['assignee_id'])||array_key_exists('priority',$input)||array_key_exists('due_at',$input)))throw new DomainException('Назначение, срок и приоритет меняет администратор');
        if($actor['role']==='admin'){
            $assignee=$input['assignee_id']??$assignee;if(!is_int($assignee))throw new InvalidArgumentException('Некорректный менеджер');if($assignee!==(int)$task['assignee_id'])taskAssignee($pdo,$assignee);
            $priority=$input['priority']??$priority;if(!in_array($priority,['LOW','NORMAL','HIGH','URGENT'],true))throw new InvalidArgumentException('Некорректный приоритет');
            $due=array_key_exists('due_at',$input)?taskDue($input['due_at']):$due;
        }
        $s=$pdo->prepare('UPDATE manager_tasks SET status=?,result=?,assignee_id=?,priority=?,due_at=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?');$s->execute([$status,$result,$assignee,$priority,$due,$id,$input['version']]);if($s->rowCount()!==1)throw new TaskConflict('Задача изменилась');
        if(in_array($status,['DONE','CANCELLED'],true))$pdo->prepare('DELETE FROM analytics_open_task_slots WHERE task_id=?')->execute([$id]);
        taskEvent($pdo,$id,(int)$actor['id'],'UPDATED',['from_status'=>$task['status'],'to_status'=>$status,'old_assignee'=>(int)$task['assignee_id'],'assignee_id'=>$assignee,'priority'=>$priority,'due_at'=>$due,'result'=>$result]);
        $pdo->commit();return ['status'=>'success'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
