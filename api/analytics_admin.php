<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/analytics_sync.php';
require_once __DIR__.'/analytics_candidate_report.php';
try{
    $pdo=getPdo();$admin=requireWebAdmin($pdo);$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $counts=$pdo->query('SELECT decision,COUNT(*) AS total FROM analytics_inbox GROUP BY decision')->fetchAll(PDO::FETCH_ASSOC);
        $after=(string)($_GET['after']??'');$s=$pdo->prepare('SELECT signal_key,source,signal_id,signal_revision,subject_key,decision,reasons_json,task_id,processed_at,payload_json FROM analytics_inbox WHERE signal_key>? ORDER BY signal_key LIMIT 51');$s->execute([$after]);$items=$s->fetchAll(PDO::FETCH_ASSOC);$more=count($items)>50;$items=array_slice($items,0,50);
        foreach($items as &$item){$payload=json_decode($item['payload_json'],true,64,JSON_THROW_ON_ERROR);unset($item['payload_json']);$item['subject']=$payload['subject'];$item['signal_type']=$payload['signal_type'];$item['last_purchase']=$payload['last_purchase'];$item['as_of']=$payload['as_of'];$item['metrics']=$payload['metrics'];$item['score']=$payload['score'];$item['confidence']=$payload['confidence'];$item['binding']=analyticsBinding($pdo,$item['subject_key']);$item['candidate']=analyticsCandidateReportLookup($payload);}unset($item);
        $completed=(int)$pdo->query("SELECT COUNT(*) FROM manager_tasks WHERE source_signal_key IS NOT NULL AND status='DONE'")->fetchColumn();
        $summary=$pdo->query("SELECT COUNT(*) AS received,SUM(CASE WHEN decision IN ('READY','DRY_RUN_READY','TASK_CREATED','TASK_EXISTS') THEN 1 ELSE 0 END) AS passed,SUM(CASE WHEN decision='BLOCKED' THEN 1 ELSE 0 END) AS blocked,SUM(CASE WHEN decision='REVIEW' OR reasons_json LIKE '%IDENTITY_%' OR reasons_json LIKE '%UNMAPPED_MANAGER%' OR reasons_json LIKE '%TECHNICAL_%' THEN 1 ELSE 0 END) AS review,SUM(CASE WHEN decision='TASK_EXISTS' THEN 1 ELSE 0 END) AS existing_tasks,SUM(CASE WHEN reasons_json LIKE '%MISSING_MANAGER%' OR reasons_json LIKE '%UNMAPPED_MANAGER%' OR reasons_json LIKE '%INACTIVE_OR_INELIGIBLE_MANAGER%' THEN 1 ELSE 0 END) AS without_eligible_manager FROM analytics_inbox")->fetch(PDO::FETCH_ASSOC);
        $summary['rejected_events']=(int)$pdo->query('SELECT COUNT(*) FROM analytics_delivery_rejections')->fetchColumn();
        $rejections=$pdo->query('SELECT run_id,signal_id,reason_code,created_at FROM analytics_delivery_rejections ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        $summary['created_tasks']=(int)$pdo->query('SELECT COUNT(*) FROM manager_tasks WHERE source_signal_key IS NOT NULL')->fetchColumn();
        sendJson(['status'=>'success','summary'=>$summary,'rejections'=>$rejections,'mode'=>analyticsIntegrationMode(),'automation_default'=>false,'counts'=>$counts,'completed_analytics_tasks'=>$completed,'items'=>$items,'next_after'=>$more?$items[count($items)-1]['signal_key']:null]);
    }
    if($method!=='POST')sendJson(['status'=>'error','message'=>'Метод не разрешён'],405);
    $body=readJsonBody();$key=taskText($body['signal_key']??null,64);
    $s=$pdo->prepare('SELECT payload_json FROM analytics_inbox WHERE signal_key=?');$s->execute([$key]);$raw=$s->fetchColumn();if(!$raw)throw new InvalidArgumentException('Сигнал не найден');
    $event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);analyticsValidateEvent($event);
    if(($body['action']??null)==='dry_run'){
        $binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));
        $clientId=$binding['client_id']??null;
        $client=$clientId?(analyticsDirectoryRecords([(string)$clientId])[(string)$clientId]??null):null;
        sendJson(['status'=>'success','decision'=>processAnalyticsEvent($pdo,$event,$client,analyticsIntegrationPolicy($pdo),'dry_run',time())]);
    }
    $status=$body['binding_status']??null;if(!in_array($status,['VERIFIED','REVIEW','AMBIGUOUS','NOT_FOUND'],true))throw new InvalidArgumentException('Некорректный статус идентификации');
    $clientId=$body['client_id']??null;if($clientId!==null)$clientId=taskText($clientId,255);
    if($status==='VERIFIED'){
        if(($body['confirm_all_sales_scope']??false)!==true)throw new InvalidArgumentException('Подтвердите проверку всей группы исторических продаж, а не только имени/телефона');
        if(($body['technical']??null)===false&&($body['confirm_nontechnical']??false)!==true)throw new InvalidArgumentException('Подтвердите, что клиент не является техническим или агрегатным');
        if(!$clientId)throw new InvalidArgumentException('Укажите настоящий ClientsVR client_id');
        if(($event['subject']['kind']??null)==='external_id'&&$clientId!==$event['subject']['key'])throw new InvalidArgumentException('ClientsVR client_id должен совпадать с external_id исходного сигнала');
        $record=analyticsDirectoryRecords([$clientId])[$clientId]??null;if(!$record)throw new InvalidArgumentException('Уникальный ID не найден в кэше ClientsVR');
    }
    $evidence=taskText($body['evidence']??null,2000);if(!is_bool($body['technical']??null))throw new InvalidArgumentException('Укажите признак технического клиента');
    $subject=analyticsSubjectKey($event['subject'],$event['run']['scope']);
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM analytics_client_bindings WHERE subject_key=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$subject]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if(!array_key_exists('expected_revision',$body)||($old?(int)$old['revision']:null)!==$body['expected_revision'])throw new TaskConflict('Привязка изменилась. Обновите список.');
        if($old)$pdo->prepare('UPDATE analytics_client_bindings SET client_id=?,status=?,technical=?,evidence=?,revision=revision+1,reviewer_id=?,updated_at=CURRENT_TIMESTAMP WHERE subject_key=?')->execute([$clientId,$status,$body['technical']?1:0,$evidence,(int)$admin['id'],$subject]);
        else $pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$subject,json_encode(['subject'=>$event['subject'],'scope'=>$event['run']['scope']],JSON_THROW_ON_ERROR),$clientId,$status,$body['technical']?1:0,$evidence,(int)$admin['id']]);
        $pdo->prepare('INSERT INTO analytics_client_binding_events(subject_key,actor_id,data_json) VALUES(?,?,?)')->execute([$subject,(int)$admin['id'],json_encode(['old_client_id'=>$old['client_id']??null,'client_id'=>$clientId,'status'=>$status,'technical'=>$body['technical'],'evidence'=>$evidence],JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Recheck selected receipt in dry_run ONLY; saving a binding cannot create a task.
    $client=$clientId?(analyticsDirectoryRecords([$clientId])[$clientId]??null):null;
    $decision=processAnalyticsEvent($pdo,$event,$client,analyticsIntegrationPolicy($pdo),'dry_run',time());
    sendJson(['status'=>'success','decision'=>$decision]);
}catch(TaskConflict $e){sendJson(['status'=>'error','message'=>$e->getMessage()],409);
}catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('analytics_admin: '.get_class($e));sendJson(['status'=>'error','message'=>'Диагностика недоступна. Проверьте миграцию и локальный кэш.'],503);}
