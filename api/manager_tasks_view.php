<?php
declare(strict_types=1);

function taskViewSignal(array $task): array {
    $e = json_decode((string)($task['evidence_json'] ?? ''), true);
    return is_array($e['signal'] ?? null) ? $e['signal'] : [];
}
function taskViewType(array $task): array {
    $signal = taskViewSignal($task);
    $key = (string)($signal['signal_type'] ?? $task['family'] ?? 'MANUAL');
    $labels = ['CHURN_RISK'=>'Риск ухода клиента', 'PURCHASE_OVERDUE'=>'Просрочена ожидаемая покупка', 'INACTIVITY'=>'Отсутствие покупок', 'MANUAL'=>'Общая'];
    return ['key'=>$key, 'label'=>$labels[$key] ?? $key];
}
function taskViewDescription(array $task): string {
    $signal = taskViewSignal($task);
    if (empty($signal['last_purchase'])) return (string)($task['description'] ?? '');
    $metrics = $signal['metrics'] ?? [];
    $interval = $metrics['median_interval'] ?? null;
    $gap = $metrics['days_without_purchase'] ?? null;
    $frequency = is_numeric($interval) && (float)$interval > 0 ? 'каждые '.$interval.' дней' : 'не определена';
    return 'Частота покупок: '.$frequency.'. Последняя покупка: '.$signal['last_purchase'].'. Без покупок: '.(is_numeric($gap) ? $gap.' дн.' : 'неизвестно.').' Связаться с клиентом, выяснить причину отсутствия заказов и зафиксировать результат.';
}
/** Scope is applied before counts, types, manager filtering and pagination. */
function taskViewScope(array $user): array {
    if (($user['role'] ?? '') === 'manager') return ['t.assignee_id=?', [(int)$user['id']]];
    if (in_array($user['role'] ?? '', ['admin','supervisor'], true)) return ['1=1', []];
    throw new DomainException('Недостаточно прав');
}
function taskViewList(PDO $pdo, array $user, array $input): array {
    [$scope,$params] = taskViewScope($user);
    $q=$pdo->prepare("SELECT COUNT(*) FROM manager_tasks t WHERE $scope AND t.status IN ('OPEN','IN_PROGRESS')");
    $q->execute($params); $count=(int)$q->fetchColumn();
    if (($input['count_only'] ?? '') === '1') return ['status'=>'success','unfinished_count'=>$count];
    $manager = (string)($input['manager'] ?? '');
    if ($manager !== '' && $user['role'] !== 'manager') {
        if (!ctype_digit($manager) || (int)$manager < 1) throw new InvalidArgumentException('Некорректный менеджер');
        $scope.=' AND t.assignee_id=?'; $params[]=(int)$manager;
    }
    $mode=(string)($input['completed'] ?? '0');
    if (!in_array($mode,['0','1'],true)) throw new InvalidArgumentException('Некорректный фильтр');
    $status=(string)($input['status'] ?? '');
    if (!in_array($status,['','OPEN','IN_PROGRESS','CANCELLED','DONE'],true)) throw new InvalidArgumentException('Некорректный статус');
    $scope .= $mode==='1' ? " AND t.status='DONE'" : " AND t.status<>'DONE'";
    if ($status !== '') {$scope.=' AND t.status=?';$params[]=$status;}
    // Signal type lives in evidence JSON; decode in PHP for MySQL/MariaDB compatibility.
    $q=$pdo->prepare("SELECT t.id,t.client_id,t.title,t.assignee_id,t.status,t.priority,t.due_at,t.version,t.family,t.evidence_json,u.display_name AS assignee_name FROM manager_tasks t JOIN web_users u ON u.id=t.assignee_id WHERE $scope ORDER BY t.id");
    $q->execute($params);$items=[];$types=[];$more=false;
    $after=(string)($input['after'] ?? '');$type=(string)($input['type'] ?? '');
    while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
        $tag=taskViewType($row);$types[$tag['key']]=$tag;
        if (($type!=='' && $tag['key']!==$type) || ($after!=='' && strcmp($row['id'],$after)<=0)) continue;
        if(count($items)>=50){$more=true;continue;}
        unset($row['evidence_json']);$row['task_type']=$tag;$items[]=$row;
    }
    $clients=analyticsDirectoryRecords(array_values(array_unique(array_column($items,'client_id'))));
    foreach($items as &$row)$row['client_name']=$clients[(string)$row['client_id']]['name'] ?? (string)$row['client_id'];
    unset($row);ksort($types);
    $assignees=$user['role']==='manager'?[]:$pdo->query("SELECT id,display_name,is_active FROM web_users WHERE role='manager' ORDER BY display_name,id")->fetchAll(PDO::FETCH_ASSOC);
    return ['status'=>'success','items'=>$items,'types'=>array_values($types),'unfinished_count'=>$count,'next_after'=>$more?end($items)['id']:null,'role'=>$user['role'],'assignees'=>$assignees];
}
