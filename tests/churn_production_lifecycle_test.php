<?php
declare(strict_types=1);
/** Disposable SQLite/MariaDB only. Executes the user-supplied production lifecycle. */
if(($argv[1]??'')==='worker'){
 require_once __DIR__.'/../api/analytics_sync.php';
 require_once __DIR__.'/../api/analytics_permanent.php';
 require_once __DIR__.'/../api/analytics_verified_bindings.php';
 if(getenv('ANALYTICS_TASK_TEST_DSN')!=='mysql:host=analytics-test-mariadb;dbname=analytics_tasks_test')throw new RuntimeException('Disposable lifecycle DB required');
 $ctx=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
 $db=new PDO(getenv('ANALYTICS_TASK_TEST_DSN'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 for($n=0;$n<10;$n++){
  try{$r=analyticsPermanentPage($db,$ctx['page'],'deliver',time());file_put_contents($argv[3],json_encode($r,JSON_THROW_ON_ERROR));exit(0);}
  catch(DomainException $e){if($e->getMessage()!=='CONSUMER_BUSY')throw $e;}
  catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','40001'],true))throw $e;}
  usleep(100000);
 }
 exit(1);
}
ob_start();require __DIR__.'/churn_permanent_cli_fixture.php';ob_end_clean();
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/../api/analytics_permanent.php';
require_once __DIR__.'/../api/manager_tasks_view.php';
function taskViewClients(array $ids):array{return array_fill_keys($ids,['name'=>'Synthetic lifecycle customer']);}
function lifecycleReject(callable $fn,string $type):void{
 try{$fn();}catch(Throwable $e){check($e instanceof $type,'Unexpected exception '.get_class($e));return;}
 throw new RuntimeException('Expected '.$type);
}
function lifecycleTask(PDO $db,string $id):array{
 $s=$db->prepare('SELECT * FROM manager_tasks WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);
}
require_once __DIR__.'/../scripts/check_churn_production_schema.php';
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
 churnProductionSchema($db);
 // Only this allowlisted disposable fixture is changed for rejection tests.
 $db->exec('DROP INDEX ix_analytics_reopened_slot ON analytics_open_task_slots');
 $db->exec('CREATE UNIQUE INDEX ix_analytics_reopened_slot ON analytics_open_task_slots(reopened_slot_key)');
 lifecycleReject(fn()=>churnProductionSchema($db),DomainException::class);
 $db->exec('DROP INDEX ix_analytics_reopened_slot ON analytics_open_task_slots');
 $db->exec('CREATE INDEX ix_analytics_reopened_slot ON analytics_open_task_slots(reopened_slot_key)');
 $db->exec('ALTER TABLE analytics_open_task_slots MODIFY reopened_slot_key VARCHAR(64) NULL');
 lifecycleReject(fn()=>churnProductionSchema($db),DomainException::class);
 $db->exec('ALTER TABLE analytics_open_task_slots MODIFY reopened_slot_key CHAR(64) NULL');
 churnProductionSchema($db);
}
putenv('CALLTRACK_ANALYTICS_POLICY_FILE='.$policyFile);
$owner=['id'=>3,'role'=>'manager'];$other=['id'=>4,'role'=>'manager'];$supervisor=['id'=>2,'role'=>'supervisor'];$admin=['id'=>1,'role'=>'admin'];
$page=['job_id'=>'lifecycle-job','release_id'=>$event['run']['id'],'events'=>[['event_id'=>1,'event_hash'=>hash('sha256',$raw),'event_json'=>$raw]]];
$created=analyticsPermanentPage($db,$page,'deliver',time());$id=$created['receipts'][0]['task_id'];
check($created['receipts'][0]['decision']==='TASK_CREATED','signed baseline task created');
$manual=createManagerTask($db,$admin,['client_id'=>'delete-fixture','assignee_id'=>3,'family'=>'INACTIVITY','title'=>'Delete fixture']);
$deleteId=$manual['task']['id'];$row=lifecycleTask($db,$deleteId);
check(taskCanRead($owner,$row)&&taskCanRead($supervisor,$row)&&taskCanRead($admin,$row)&&!taskCanRead($other,$row),'pre-delete ACL');
$before=taskViewList($db,$owner,['count_only'=>'1'])['unfinished_count'];
lifecycleReject(fn()=>deleteManagerTask($db,$owner,$deleteId,1),DomainException::class);
lifecycleReject(fn()=>deleteManagerTask($db,$admin,$deleteId,0),InvalidArgumentException::class);
lifecycleReject(fn()=>deleteManagerTask($db,$admin,$deleteId,99),TaskConflict::class);
deleteManagerTask($db,$supervisor,$deleteId,1);
$row=lifecycleTask($db,$deleteId);
check(!empty($row['deleted_at'])&&(int)$row['version']===2,'soft delete preserves row and increments version');
foreach([$owner,$other,$admin,$supervisor] as $actor)check(!taskCanRead($actor,$row),'deleted ACL denies every role');
lifecycleReject(fn()=>updateManagerTask($db,$admin,$deleteId,['version'=>2,'status'=>'OPEN']),DomainException::class);
lifecycleReject(fn()=>deleteManagerTask($db,$admin,$deleteId,2),TaskConflict::class);
$s=$db->prepare('SELECT COUNT(*) FROM analytics_open_task_slots WHERE task_id=?');$s->execute([$deleteId]);check((int)$s->fetchColumn()===0,'delete releases slot');
$s=$db->prepare("SELECT COUNT(*) FROM manager_task_events WHERE task_id=? AND event_type='DELETED'");$s->execute([$deleteId]);check((int)$s->fetchColumn()===1,'delete audit retained');
check(taskViewList($db,$owner,['count_only'=>'1'])['unfinished_count']===$before-1,'deleted excluded from count');
foreach([$owner,$admin,$supervisor] as $actor){
 foreach([[],['completed'=>'1'],['status'=>'OPEN'],['type'=>'INACTIVITY'],['manager'=>'3']] as $filter){
  check(!in_array($deleteId,array_column(taskViewList($db,$actor,$filter)['items'],'id'),true),'deleted excluded before list/filter pagination');
 }
}
check(!in_array('INACTIVITY',array_column(taskViewList($db,$owner,[])['types'],'key'),true),'deleted-only type excluded');
$doneManual=createManagerTask($db,$admin,['client_id'=>'deleted-done','assignee_id'=>3,'family'=>'MANUAL','title'=>'Deleted done']);
$doneId=$doneManual['task']['id'];
updateManagerTask($db,$owner,$doneId,['version'=>1,'status'=>'DONE','result'=>'']);
deleteManagerTask($db,$admin,$doneId,2);
check(!in_array($doneId,array_column(taskViewList($db,$owner,['completed'=>'1'])['items'],'id'),true),'deleted completed task hidden');
// Manual reopen preserves optimistic locking and role controls.
lifecycleReject(fn()=>updateManagerTask($db,$supervisor,$id,['version'=>1,'status'=>'DONE']),DomainException::class);
lifecycleReject(fn()=>updateManagerTask($db,$other,$id,['version'=>1,'status'=>'DONE']),DomainException::class);
updateManagerTask($db,$owner,$id,['version'=>1,'status'=>'DONE','result'=>'']);
updateManagerTask($db,$owner,$id,['version'=>2,'status'=>'IN_PROGRESS','result'=>'Manual reopen']);
$reopened=lifecycleTask($db,$id);
check($reopened['status']==='IN_PROGRESS'&&(int)$reopened['version']===3,'owner can reopen completed task');
lifecycleReject(fn()=>updateManagerTask($db,$owner,$id,['version'=>2,'result'=>'stale']),TaskConflict::class);
lifecycleReject(fn()=>updateManagerTask($db,$owner,$id,['version'=>3,'assignee_id'=>4]),DomainException::class);
$slot=automaticTaskSlot('signed-client','INACTIVITY',['evidence'=>['signal'=>$event]]);
$s=$db->prepare('SELECT slot_key,reopened_slot_key FROM analytics_open_task_slots WHERE task_id=?');$s->execute([$id]);$alias=$s->fetch(PDO::FETCH_ASSOC);
check($alias['slot_key']===hash('sha256','reopened-task:'.$id)&&$alias['reopened_slot_key']===$slot,'reopen uses non-primary alias');
check(findOpenTaskInSlot($db,$slot)['id']===$id,'slot lookup finds reopened alias');
$later=$event;$later['signal_id']='later-anchor';$later['last_purchase']='2026-08-01';
check(findAutomaticManagerTask($db,'signed-client',['signal_key'=>analyticsSignalKey($later),'evidence'=>['signal'=>$later]])['id']===$id,'later episode lookup respects reopened slot');
$count=(int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn();
$reservations=(int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn();
$replay=analyticsPermanentPage($db,$page,'deliver',time());
check($replay['receipts'][0]['decision']==='TASK_EXISTS'&&$replay['receipts'][0]['task_id']===$id,'signed replay after reopen returns existing task');
check(lifecycleTask($db,$id)===$reopened,'receiver does not change reopened task');
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
 $ctx=$storage.'/lifecycle-context.json';file_put_contents($ctx,json_encode(['page'=>$page],JSON_THROW_ON_ERROR));chmod($ctx,0600);
 $children=[];
 foreach([1,2] as $n){$pipes=[];$children[]=proc_open([PHP_BINARY,__FILE__,'worker',$ctx,$storage.'/lifecycle-'.$n.'.json'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
 foreach($children as $child)check(is_resource($child)&&proc_close($child)===0,'concurrent lifecycle worker');
 foreach([1,2] as $n){$r=json_decode(file_get_contents($storage.'/lifecycle-'.$n.'.json'),true,64,JSON_THROW_ON_ERROR);check($r['receipts'][0]['task_id']===$id&&$r['receipts'][0]['decision']==='TASK_EXISTS','concurrent delivery after reopen reuses task');}
}
check((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===$count,'reopen replay creates no duplicate');
check((int)$db->query('SELECT COUNT(*) FROM analytics_permanent_reservations')->fetchColumn()===$reservations,'reopen replay consumes no budget');
// Production allows reopening even when a newer task occupies the original slot.
updateManagerTask($db,$owner,$id,['version'=>3,'status'=>'DONE']);
$newManual=createManagerTask($db,$admin,['client_id'=>'signed-client','assignee_id'=>3,'family'=>'INACTIVITY','title'=>'Newer open work']);
updateManagerTask($db,$owner,$id,['version'=>4,'status'=>'OPEN']);
$otherId=$newManual['task']['id'];check($otherId!==$id,'newer primary slot task retained');
$s=$db->prepare('SELECT COUNT(*) FROM analytics_open_task_slots WHERE slot_key=? OR reopened_slot_key=?');$s->execute([$slot,$slot]);check((int)$s->fetchColumn()===2,'primary and reopened alias may coexist');
$reuse=createManagerTask($db,$admin,['client_id'=>'signed-client','assignee_id'=>3,'family'=>'INACTIVITY','title'=>'Must reuse']);
check($reuse['existing']&&in_array($reuse['task']['id'],[$id,$otherId],true),'no third task in reopened group');
// Cancellation restrictions remain exactly as provided by production.
lifecycleReject(fn()=>updateManagerTask($db,$owner,$otherId,['version'=>1,'status'=>'CANCELLED']),DomainException::class);
updateManagerTask($db,$admin,$otherId,['version'=>1,'status'=>'CANCELLED']);
updateManagerTask($db,$owner,$otherId,['version'=>2,'status'=>'CANCELLED','result'=>'Owner edits cancelled task']);
updateManagerTask($db,$owner,$otherId,['version'=>3,'status'=>'OPEN']);
// Re-delivering a deleted historical episode never restores it or creates a clone.
deleteManagerTask($db,$admin,$id,5);$deleted=lifecycleTask($db,$id);
$count=(int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn();
$again=analyticsPermanentPage($db,$page,'deliver',time());
check($again['receipts'][0]['task_id']===$id,'deleted episode remains deduplicated');
check(lifecycleTask($db,$id)===$deleted,'receiver never resurrects deleted task');
check((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===$count,'no clone of deleted episode');
check(lifecycleTask($db,$otherId)['status']==='OPEN','unrelated reopened work unchanged');
echo "churn_production_lifecycle_test: soft delete, deleted ACL/list/count/types, versions, reopen aliases, occupied slot, signed replay and concurrent delivery OK\n";
