<?php
declare(strict_types=1);
require_once __DIR__.'/manager_tasks_service.php';
function analyticsTaskPolicy(PDO $pdo,bool $forUpdate=false): array {
    $lock=$forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $row=$pdo->query('SELECT * FROM analytics_task_policy WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Policy migration missing');
    $row['types']=$pdo->query('SELECT signal_type,enabled,priority,due_days FROM analytics_task_type_policy ORDER BY signal_type'.$lock)->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}
function analyticsTaskPolicyGateConfig(array $row): array {
    $result=['policy_approved'=>(int)$row['approved']===1,'policy_revision'=>(int)$row['revision'],'min_confidence'=>$row['min_confidence'],'enabled_types'=>[],'priorities'=>[],'due_days_by_type'=>[]];
    foreach($row['types'] as $type){
        if((int)$type['enabled']===1)$result['enabled_types'][]=$type['signal_type'];
        $result['priorities'][$type['signal_type']]=$type['priority'];
        $result['due_days_by_type'][$type['signal_type']]=$type['due_days']===null?null:(int)$type['due_days'];
    }return $result;
}
function saveAnalyticsTaskPolicy(PDO $pdo,array $actor,array $body): void {
    if(($actor['role']??'')!=='admin')throw new DomainException('Только администратор может менять политику');
    if(!is_int($body['revision']??null)||!in_array($body['action']??null,['save_draft','approve'],true))throw new InvalidArgumentException('Передайте revision и action');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $row=$pdo->query('SELECT * FROM analytics_task_policy WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['revision']!==$body['revision'])throw new TaskConflict('Политика изменилась. Обновите страницу.');
        $action=$body['action'];
        if($action==='save_draft'){
            $minimum=$body['min_confidence']??null;
            if($minimum!==null&&(!is_numeric($minimum)||!is_finite((float)$minimum)||(float)$minimum<0||(float)$minimum>1))throw new InvalidArgumentException('Confidence должен быть от 0 до 1 либо не задан');
            if(!is_array($body['types']??null)||count($body['types'])!==2)throw new InvalidArgumentException('Нужны настройки обоих типов');
            $seen=[];
            foreach($body['types'] as $type){
                $kind=$type['signal_type']??'';if(!in_array($kind,['PURCHASE_OVERDUE','CHURN_RISK'],true)||isset($seen[$kind])||!is_bool($type['enabled']??null))throw new InvalidArgumentException('Некорректный тип');$seen[$kind]=true;
                $priority=$type['priority']??null;$days=$type['due_days']??null;
                if($priority!==null&&!in_array($priority,['LOW','NORMAL','HIGH','URGENT'],true))throw new InvalidArgumentException('Некорректный приоритет');
                if($days!==null&&(!is_int($days)||$days<0||$days>365))throw new InvalidArgumentException('Срок должен быть целым числом дней 0–365 либо не задан');
                $pdo->prepare('UPDATE analytics_task_type_policy SET enabled=?,priority=?,due_days=? WHERE signal_type=?')->execute([$type['enabled']?1:0,$priority,$days,$kind]);
            }
            $pdo->prepare('UPDATE analytics_task_policy SET min_confidence=?,approved=0,approved_by=NULL,approved_at=NULL,revision=revision+1 WHERE id=1')->execute([$minimum]);
        }else{
            if(($body['confirm_business_approval']??false)!==true)throw new InvalidArgumentException('Требуется явное подтверждение утверждения бизнес-политики');
            $policy=analyticsTaskPolicy($pdo);$enabled=0;
            if($policy['min_confidence']===null)throw new InvalidArgumentException('Сначала задайте confidence');
            foreach($policy['types'] as $type)if((int)$type['enabled']===1){$enabled++;if($type['priority']===null||$type['due_days']===null)throw new InvalidArgumentException('Для включённых типов задайте приоритет и срок');}
            if(!$enabled)throw new InvalidArgumentException('Ни один тип не включён');
            $pdo->prepare('UPDATE analytics_task_policy SET approved=1,approved_by=?,approved_at=CURRENT_TIMESTAMP,revision=revision+1 WHERE id=1')->execute([(int)$actor['id']]);
        }
        $updated=analyticsTaskPolicy($pdo);
        $pdo->prepare('INSERT INTO analytics_task_policy_events(actor_id,revision,action,policy_json) VALUES(?,?,?,?)')->execute([(int)$actor['id'],(int)$updated['revision'],$action,json_encode($updated,JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
