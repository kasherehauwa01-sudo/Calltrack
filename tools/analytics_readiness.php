<?php
declare(strict_types=1);
/** Read-only, bounded aggregate diagnostics. No names, phones, tokens or task writes. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../api/analytics_preliminary.php';
try{
 $db=getPdo();$db->exec('SET SESSION max_statement_time=5');$db->exec('START TRANSACTION READ ONLY');
 $managers=managerBindingRows($db);$policy=analyticsTaskPolicy($db);
 $before=clientCatalogRevision();$n=0;$started=microtime(true);
 $counts=['CUSTOMER'=>0,'TECHNICAL'=>0,'AGGREGATE'=>0,'UNKNOWN'=>0,'archived'=>0,'owner_3'=>0,'owner_6'=>0,'owner_other_or_unresolved'=>0];
 foreach(analyticsReviewDirectoryRows() as $row){
  if(++$n>500000||microtime(true)-$started>20)throw new DomainException('Local directory bound exceeded; no completeness claim');
  $f=is_array($row['fields']??null)?$row['fields']:[];$kind=$f['analytics_customer_kind']??null;
  $counts[in_array($kind,['CUSTOMER','TECHNICAL','AGGREGATE'],true)?$kind:'UNKNOWN']++;
  if(in_array($f['status']??$f['Статус']??'', ['archived','Архивный'],true))$counts['archived']++;
  $m=clientManagerField($f);$binding=resolveBoundManager($m['label'],$managers);
  $id=!$m['conflict']&&$binding['status']==='VERIFIED'?$binding['user_id']:null;
  $counts[$id===3?'owner_3':($id===6?'owner_6':'owner_other_or_unresolved')]++;
 }
 if(clientCatalogRevision()!==$before)throw new DomainException('Cache changed during scan; retry');
 $db->rollBack();
 echo json_encode(['status'=>'LOCAL_METADATA_ONLY','cache_revision'=>$before,'rows'=>$n,'counts'=>$counts,
  'task_policy_approved'=>$policy['approved']??null,'automation_allowed'=>false,'tasks_created'=>0,
  'warning'=>'Counts are cards, not unique eligible buyers; local cache is not a claim of upstream freshness. UNKNOWN classification blocks preliminary matching.'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,'READINESS_BLOCKED: '.get_class($e).PHP_EOL);exit(1);}
