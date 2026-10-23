<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
/** Explicit operator tooling only. Never included by application request handlers. */
function migrationRows(PDO $db,string $sql,array $args=[]):array{$s=$db->prepare($sql);$s->execute($args);return array_map(fn($row)=>array_map(fn($v)=>$v===null?null:(string)$v,$row),$s->fetchAll(PDO::FETCH_ASSOC));}
function migrationTable(PDO $db,string $table):?array {
 $meta=migrationRows($db,'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
 if(!$meta)return null;
 return ['table'=>$meta[0],
  'columns'=>migrationRows($db,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table]),
  'indexes'=>migrationRows($db,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$table]),
  'constraints'=>migrationRows($db,'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',[$table]),
  'checks'=>migrationRows($db,'SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',[$table]),
  'foreign_keys'=>migrationRows($db,'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,CASE WHEN k.REFERENCED_TABLE_SCHEMA=DATABASE() THEN \'__SELF__\' ELSE k.REFERENCED_TABLE_SCHEMA END AS REFERENCED_SCHEMA,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',[$table])];
}
function migrationStatements(string $sql):array {
 // Only reviewed files with matching SHA256 are accepted. Their literals contain no semicolons.
 $sql=preg_replace('/^\s*--[^\n]*(\n|$)/m','',$sql);
 return array_values(array_filter(array_map('trim',explode(';',$sql)),fn($s)=>$s!==''));
}
function migrationHasRows(PDO $db,string $table,string $where='1'):bool {
 if(!migrationTable($db,$table))return false;
 return (bool)$db->query('SELECT 1 FROM `'.$table.'` WHERE '.$where.' LIMIT 1')->fetchColumn();
}
function migrationSeedSafe(PDO $db,string $table):bool {
 // Never invent zero counters/cursors over evidence of prior automated processing.
 if(migrationHasRows($db,'manager_tasks','source_signal_key IS NOT NULL'))return false;
 foreach(['analytics_inbox','analytics_processing_events','analytics_task_budget_events','analytics_daily_budget','analytics_daily_budget_reservations'] as $name)
  if(migrationHasRows($db,$name))return false;
 return !migrationHasRows($db,$table);
}
function migrationSeedState(PDO $db,string $table):string {
 $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
 if(!$rows)return migrationSeedSafe($db,$table)?'MISSING_SAFE':'MISSING_UNSAFE';
 if($table==='analytics_task_type_policy'){
  if(count($rows)!==2||array_diff(['CHURN_RISK','PURCHASE_OVERDUE'],array_column($rows,'signal_type')))return 'INVALID';
  foreach($rows as $r)if(!in_array((int)$r['enabled'],[0,1],true)||($r['priority']!==null&&!in_array($r['priority'],['LOW','NORMAL','HIGH','URGENT'],true))||($r['due_days']!==null&&((int)$r['due_days']<0||(int)$r['due_days']>365)))return 'INVALID';
  return 'VALID';
 }
 if(count($rows)!==1||(int)$rows[0]['id']!==1)return 'INVALID';$r=$rows[0];
 if($table==='analytics_daily_budget_policy')return $r['policy_version']==='aviator-churn-pilot/1'&&$r['timezone']==='Europe/Moscow'&&(int)$r['max_tasks']===5?'VALID':'INVALID';
 if($table==='analytics_task_budget')return in_array((int)$r['approved'],[0,1],true)&&(int)$r['revision']>=1&&(int)$r['used_tasks']>=0&&(!(int)$r['approved']||$r['max_tasks']!==null)&&($r['max_tasks']===null||(int)$r['max_tasks']>=(int)$r['used_tasks'])?'VALID':'INVALID';
 if($table==='analytics_task_policy')return in_array((int)$r['approved'],[0,1],true)&&(int)$r['revision']>=1&&($r['min_confidence']===null||is_numeric($r['min_confidence'])&&is_finite((float)$r['min_confidence'])&&(float)$r['min_confidence']>=0&&(float)$r['min_confidence']<=1)&&(!(int)$r['approved']||$r['min_confidence']!==null)?'VALID':'INVALID';
 if(in_array($table,['analytics_sync_state','analytics_automation_sync_state'],true))return (int)$r['publication_sequence']>=0&&(int)$r['after_change']>=0&&(($r['current_run_id']===null)===($r['current_sequence']===null))?'VALID':'INVALID';
 throw new RuntimeException('Unknown seed contract');
}
function migrationInspect(PDO $db,array $manifest):array {
 $issues=[];$files=[];$parent=migrationTable($db,'web_users');
 if(!$parent||$parent['table']['ENGINE']!=='InnoDB')return ['state'=>'BLOCKED','issues'=>['PREREQUISITE_WEB_USERS'],'migrations'=>[]];
 $id=array_values(array_filter($parent['columns'],fn($c)=>$c['COLUMN_NAME']==='id'));
 if(count($id)!==1||$id[0]['COLUMN_TYPE']!=='bigint(20) unsigned'||!array_filter($parent['indexes'],fn($i)=>$i['INDEX_NAME']==='PRIMARY'&&$i['COLUMN_NAME']==='id'))return ['state'=>'SCHEMA_DRIFT','issues'=>['PREREQUISITE_WEB_USERS_ID'],'migrations'=>[]];
 // All structure is verified before reading seed/operational columns or making writes.
 foreach($manifest['migrations'] as $file=>$m){
  $present=0;$missing=0;$drift=[];
  foreach($m['tables'] as $name=>$expected){$actual=migrationTable($db,$name);if($actual===null){$missing++;continue;}$present++;
   if($actual!==$expected){$parts=[];foreach($expected as $part=>$value)if(($actual[$part]??null)!==$value)$parts[]=$part;$drift[]=$name.':DEFINITION_MISMATCH:'.implode(',',$parts);}
  }
  $files[$file]=['state'=>$drift?'SCHEMA_DRIFT':($missing?($present?'PARTIALLY_APPLIED':'NOT_APPLIED'):'APPLIED_AND_VERIFIED'),'issues'=>$drift];$issues=array_merge($issues,$drift);
 }
 if($issues)return ['state'=>'SCHEMA_DRIFT','migrations'=>$files,'issues'=>$issues];
 foreach($manifest['migrations'] as $file=>$m)foreach($m['seeds'] as $name){
  if(!migrationTable($db,$name)){if(!migrationSeedSafe($db,$name)){$issues[]=$name.':SEED_MISSING_UNSAFE';$files[$file]['state']='SCHEMA_DRIFT';}continue;}$seed=migrationSeedState($db,$name);
  if($seed==='MISSING_SAFE')$files[$file]['state']='PARTIALLY_APPLIED';
  elseif($seed!=='VALID'){$issues[]=$name.':SEED_'.$seed;$files[$file]['state']='SCHEMA_DRIFT';$files[$file]['issues'][]=$name.':SEED_'.$seed;}
 }
 if(migrationTable($db,'analytics_task_policy')&&migrationTable($db,'analytics_task_type_policy')){
  $policy=$db->query('SELECT approved FROM analytics_task_policy WHERE id=1')->fetchColumn();
  if((int)$policy===1){$types=$db->query('SELECT enabled,priority,due_days FROM analytics_task_type_policy')->fetchAll(PDO::FETCH_ASSOC);$enabled=array_filter($types,fn($t)=>(int)$t['enabled']===1);
   if(!$enabled||array_filter($enabled,fn($t)=>$t['priority']===null||$t['due_days']===null))$issues[]='APPROVED_TASK_POLICY_INCOMPLETE';}
 }
 if(migrationTable($db,'analytics_daily_budget')&&migrationTable($db,'analytics_daily_budget_reservations')){
  $bad=$db->query('SELECT d.business_date FROM analytics_daily_budget d LEFT JOIN analytics_daily_budget_reservations r ON r.policy_id=d.policy_id AND r.business_date=d.business_date GROUP BY d.policy_id,d.business_date,d.used_tasks HAVING d.used_tasks<>COUNT(r.reservation_key) OR d.used_tasks<0 OR d.used_tasks>5 LIMIT 1')->fetchColumn();
  if($bad!==false)$issues[]='DAILY_COUNTER_LEDGER_MISMATCH';
 }
 $states=array_column($files,'state');
 $state=$issues?'SCHEMA_DRIFT':(count(array_unique($states))===1?$states[0]:'PARTIALLY_APPLIED');
 return ['state'=>$state,'migrations'=>$files,'issues'=>$issues];
}
