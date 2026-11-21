<?php
declare(strict_types=1);
/** Read-only schema contract; never adds or repairs lifecycle columns/indexes. */
function churnProductionSchema(PDO $db):void{
 if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new DomainException('MARIADB_SCHEMA_REQUIRED');
 $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('manager_tasks','analytics_open_task_slots')")->fetchAll(PDO::FETCH_KEY_PAIR);
 if(count($engines)!==2||array_filter($engines,fn($engine)=>$engine!=='InnoDB'))throw new DomainException('INNODB_SCHEMA_REQUIRED');
 $s=$db->prepare('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->execute(['manager_tasks']);$columns=array_column($s->fetchAll(PDO::FETCH_ASSOC),null,'COLUMN_NAME');
 if(($columns['deleted_at']['DATA_TYPE']??null)!=='timestamp'||($columns['deleted_at']['IS_NULLABLE']??null)!=='YES')throw new DomainException('DELETED_AT_SCHEMA_MISMATCH');
 $s->execute(['analytics_open_task_slots']);$columns=array_column($s->fetchAll(PDO::FETCH_ASSOC),null,'COLUMN_NAME');
 if(($columns['reopened_slot_key']['DATA_TYPE']??null)!=='char'||(int)($columns['reopened_slot_key']['CHARACTER_MAXIMUM_LENGTH']??0)!==64)throw new DomainException('REOPENED_SLOT_SCHEMA_MISMATCH');
 $expected=[
  'analytics_open_task_slots'=>[
   'PRIMARY'=>['unique'=>true,'columns'=>['slot_key']],
   'task_id'=>['unique'=>true,'columns'=>['task_id']],
   'ix_analytics_reopened_slot'=>['unique'=>false,'columns'=>['reopened_slot_key']]],
  'manager_tasks'=>[
   'PRIMARY'=>['unique'=>true,'columns'=>['id']],
   'uq_task_signal'=>['unique'=>true,'columns'=>['source_signal_key']],
   'ix_task_owner_status'=>['unique'=>false,'columns'=>['assignee_id','status','due_at']]]];
 $s=$db->prepare('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
 foreach($expected as $table=>$indexes){
  $s->execute([$table]);$actual=[];
  foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
   if($row['SUB_PART']!==null)throw new DomainException('PREFIX_INDEX_NOT_REVIEWED');
   $name=$row['INDEX_NAME'];
   if(!isset($actual[$name]))$actual[$name]=['unique'=>(int)$row['NON_UNIQUE']===0,'columns'=>[]];
   $actual[$name]['columns'][]=$row['COLUMN_NAME'];
  }
  ksort($actual);ksort($indexes);
  if($actual!==$indexes)throw new DomainException('LIFECYCLE_INDEX_SCHEMA_MISMATCH');
 }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
 try{
  require_once '/var/www/html/vr/calltrack-v3-20261026/api/config.php';
  $db=getPdo();churnProductionSchema($db);
  $version=(string)$db->query('SELECT VERSION()')->fetchColumn();
  if(PHP_VERSION!=='8.5.12'||!preg_match('/^11[.]8[.]6(?:-|$)/D',$version))throw new DomainException('FIRSTVDS_VERSION_NOT_REVIEWED');
  echo json_encode(['schema'=>'compatible','php'=>PHP_VERSION,'database'=>$version],JSON_THROW_ON_ERROR).PHP_EOL;
 }catch(Throwable $e){fwrite(STDERR,"Lifecycle schema check failed; no database changes performed\n");exit(1);}
}
