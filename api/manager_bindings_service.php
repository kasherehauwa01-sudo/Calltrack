<?php
declare(strict_types=1);
require_once __DIR__.'/client_manager.php';
require_once __DIR__.'/web_auth.php';

class ManagerBindingConflict extends RuntimeException {}
function managerBindingAdmin(array $actor): void
{
    if(($actor['role']??'')!=='admin'||(int)($actor['id']??0)<=0)throw new DomainException('Доступ разрешён только администратору');
}
function managerBindingRows(PDO $pdo,bool $forUpdate=false): array
{
    return $pdo->query('SELECT b.*,u.display_name,u.role,u.is_active FROM client_manager_bindings b LEFT JOIN web_users u ON u.id=b.web_user_id ORDER BY b.designation'.($forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
}

/** No API-triggered full upstream import. Counts are explicitly local-cache counts. */
function managerDesignationDirectory(iterable $rows): array
{
    $result=[];
    foreach($rows as $row){
        $fields=is_array($row['fields']??null)?$row['fields']:[];
        $assignment=clientManagerField($fields);
        if($assignment['conflict'])continue;
        $label=$assignment['label'];if($label==='')continue;
        $key=clientManagerDesignationKey($label);
        if(!isset($result[$key]))$result[$key]=['designation_key'=>$key,'designation'=>$label,'cached_card_count'=>0];
        $result[$key]['cached_card_count']++;
        if(count($result)>10000)throw new RuntimeException('Слишком много обозначений менеджеров');
    }
    uasort($result,static fn($a,$b)=>strcmp($a['designation'],$b['designation']));
    return array_values($result);
}

/** Only explicit aliases authorize new analytics assignments. Suggestions are not grants. */
function resolveBoundManager(string $label,array $bindings): array
{
    if(trim($label)==='')return ['status'=>'MISSING_MANAGER','user_id'=>null];
    $key=clientManagerDesignationKey($label);
    foreach($bindings as $binding){
        if($binding['designation_key']!==$key)continue;
        if(($binding['role']??'')!=='manager'||(int)($binding['is_active']??0)!==1)return ['status'=>'INACTIVE_OR_INELIGIBLE_MANAGER','user_id'=>null];
        return ['status'=>'VERIFIED','user_id'=>(int)$binding['web_user_id'],'binding_revision'=>(int)$binding['revision']];
    }
    return ['status'=>'UNMAPPED_MANAGER','user_id'=>null];
}

/** Compare-and-swap prevents one admin from silently taking another admin's alias. */
function saveManagerBinding(PDO $pdo,array $actor,string $label,?int $userId,?int $expectedRevision): void
{
    managerBindingAdmin($actor);
    $label=trim($label);
    if($label===''||mb_strlen($label)>255)throw new InvalidArgumentException('Некорректное обозначение менеджера');
    if($userId!==null&&$userId<=0)throw new InvalidArgumentException('Некорректный пользователь');
    if($expectedRevision!==null&&$expectedRevision<1)throw new InvalidArgumentException('Некорректная revision');
    $key=clientManagerDesignationKey($label);
    $pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $stmt=$pdo->prepare('SELECT web_user_id,revision FROM client_manager_bindings WHERE designation_key=?'.$lock);$stmt->execute([$key]);$old=$stmt->fetch(PDO::FETCH_ASSOC);
        if(($old?(int)$old['revision']:null)!==$expectedRevision)throw new ManagerBindingConflict('Привязка изменилась. Обновите список перед сохранением.');
        if($userId!==null){
            $stmt=$pdo->prepare('SELECT role,is_active FROM web_users WHERE id=?'.$lock);$stmt->execute([$userId]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$user||$user['role']!=='manager'||(int)$user['is_active']!==1)throw new InvalidArgumentException('Выберите активного менеджера Calltrack');
        }
        if($userId===null){
            if(!$old)throw new ManagerBindingConflict('Привязка не найдена');
            $pdo->prepare('DELETE FROM client_manager_bindings WHERE designation_key=?')->execute([$key]);
        }elseif($old){
            $pdo->prepare('UPDATE client_manager_bindings SET web_user_id=?,designation=?,revision=revision+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE designation_key=?')->execute([$userId,$label,(int)$actor['id'],$key]);
        }else{
            $history=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM client_manager_binding_events WHERE designation_key=?');$history->execute([$key]);$revision=(int)$history->fetchColumn()+1;
            $pdo->prepare('INSERT INTO client_manager_bindings(designation_key,designation,web_user_id,updated_by,revision) VALUES(?,?,?,?,?)')->execute([$key,$label,$userId,(int)$actor['id'],$revision]);
        }
        $pdo->prepare('INSERT INTO client_manager_binding_events(designation_key,old_user_id,new_user_id,actor_id) VALUES(?,?,?,?)')->execute([$key,$old?(int)$old['web_user_id']:null,$userId,(int)$actor['id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&in_array((string)$e->getCode(),['23000','23505'],true))throw new ManagerBindingConflict('Привязка уже занята или изменена');
        throw $e;
    }
}
