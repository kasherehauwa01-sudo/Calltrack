<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_signed_evidence.php';
function analyticsNormalizeNameV1(string $name):string {
 return mb_strtolower(trim(preg_replace('/[\x{0009}-\x{000d} \x{0085}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+/u',' ',$name)??'', ' '),'UTF-8');
}
function analyticsReviewDirectoryRows():iterable {
 if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())){
  $db=new PDO('sqlite:'.clientsSqliteFile(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON');
  foreach($db->query('SELECT CASE WHEN length(payload_json)<=4194304 THEN payload_json ELSE NULL END AS payload_json FROM clients') as $row){
   if(!is_string($row['payload_json'])||strlen($row['payload_json'])>4194304)throw new DomainException('Directory record exceeds bound');
   $payload=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new DomainException('Invalid directory record');yield $payload;
  }
 }elseif(is_file(clientsRecordsReadyFile())){
  $files=glob(clientsRecordsDirectory().'/*.json')?:[];if(!$files)throw new DomainException('Ready marker without directory records');
  foreach($files as $file)yield from (new ClientCatalogJsonStream($file))->rows();
 }elseif(is_file(clientsCacheFile())){yield from (new ClientCatalogJsonStream(clientsCacheFile()))->rows();}
 else throw new DomainException('Directory source unavailable');
}
function analyticsGroupCacheKey(string $name,array $managers,string $revision):string {
 return hash('sha256',analyticsEvidenceJson(['name'=>$name,'managers'=>$managers,'cache'=>$revision]));
}
/** One bounded local-directory pass per delivery page, no full upstream request. */
function analyticsPrimeReviewGroups(array $events,array $managers,string $revision):void {
 $wanted=[];
 foreach($events as $event){
  if(($event['mode']??'')!=='AUTOMATION_APPROVED')continue;
  $p=json_decode($event['binding_receipt']['payload_json']??'',true);
  if(is_array($p)&&is_string($p['client_key']??null))$wanted[$p['client_key']]=[];
 }
 analyticsPrimeReviewNames(array_keys($wanted),$managers,$revision);
}
function analyticsPrimeReviewNames(array $names,array $managers,string $revision):void {
 $GLOBALS['analytics_verified_group_cache']=[];$wanted=array_fill_keys($names,[]);
 if(!$wanted)return;if(count($wanted)>50)throw new DomainException('Review batch exceeds page bound');
 $count=0;$bytes=0;foreach(analyticsReviewDirectoryRows() as $row){
  if(++$count>500000)throw new DomainException('Directory completeness unknown');
  $name=analyticsNormalizeNameV1((string)($row['name']??''));if(!array_key_exists($name,$wanted))continue;
  $bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if($bytes>8*1024*1024)throw new DomainException('Review page memory bound exceeded');
  $wanted[$name][]=$row;if(count($wanted[$name])>500)throw new DomainException('Group exceeds bound');
 }
 foreach($wanted as $name=>$rows){
  $e=['subject'=>['kind'=>'normalized_name','version'=>1,'key'=>$name]];
  $GLOBALS['analytics_verified_group_cache'][analyticsGroupCacheKey($name,$managers,$revision)]=analyticsReviewGroup($e,$rows,$managers,$revision);
 }
}
function analyticsCurrentReviewGroup(array $event,array $managers,string $revision):array {
 $key=analyticsGroupCacheKey($event['subject']['key'],$managers,$revision);
 return $GLOBALS['analytics_verified_group_cache'][$key]??analyticsReviewGroup($event,analyticsReviewDirectoryRows(),$managers,$revision);
}
/** All local cards in this analytical name group; phones never confer identity. */
function analyticsReviewGroup(array $event,iterable $rows,array $managers,string $cacheRevision):array {
 $key=$event['subject']['key']??'';
 if(($event['subject']['kind']??'')!=='normalized_name'||($event['subject']['version']??0)!==1||analyticsNormalizeNameV1($key)!==$key)throw new DomainException('Original normalized-name signal required');
 $cards=[];$owners=[];$reasons=[];$seen=0;$bytes=0;
 foreach($rows as $row){
  if(++$seen>500000)throw new RuntimeException('Directory bound exceeded; completeness unknown');
  if(analyticsNormalizeNameV1((string)($row['name']??''))!==$key)continue;
  $bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if($bytes>8*1024*1024)throw new DomainException('Review group memory bound exceeded');
  if(!is_string($row['id']??null)&&!is_int($row['id']??null))throw new DomainException('Invalid stable client ID');
  $id=(string)$row['id'];if(strlen($id)>255)throw new DomainException('Stable client ID exceeds contract');
  $fields=is_array($row['fields']??null)?$row['fields']:[];$manager=clientManagerField($fields);
  if($id===''||isset($cards[$id]))throw new DomainException('Missing/duplicate client ID in group');
  $resolved=resolveBoundManager($manager['label'],$managers);$technical=($manager['label']===''&&!$manager['conflict'])||in_array($fields['analytics_customer_kind']??null,['TECHNICAL','AGGREGATE'],true);
  $archived=in_array($fields['status']??$fields['Статус']??'', ['archived','Архивный'],true);
  if(!$technical&&!$archived&&$manager['conflict'])$reasons[]='MANAGER_FIELD_CONFLICT';
  elseif(!$technical&&!$archived&&$resolved['status']!=='VERIFIED')$reasons[]=$resolved['status'];
  if(!$technical&&!$archived&&$resolved['user_id']!==null)$owners[$resolved['user_id']]=true;
  $cards[$id]=['client_id'=>$id,'name'=>$row['name'],'phones'=>$row['phones']??[],
   'manager'=>$manager['label'],'manager_id'=>$resolved['user_id'],'manager_binding_revision'=>$resolved['binding_revision']??null,
   'technical'=>$technical,'archived'=>$archived];
  foreach(['analytics_customer_kind'=>'customer_kind','analytics_customer_identity'=>'customer_identity','analytics_primary_client_id'=>'primary_client_id'] as $field=>$projected)if(array_key_exists($field,$fields))$cards[$id][$projected]=$fields[$field];
  if(count($cards)>500)throw new DomainException('Group too large for safe review');
 }
 ksort($cards,SORT_STRING);$owners=array_keys($owners);sort($owners,SORT_NUMERIC);
 if(!$cards)$reasons[]='CLIENT_GROUP_NOT_FOUND';
 if(count($owners)!==1)$reasons[]='MANAGER_GROUP_CONFLICT_OR_MISSING';
 if(count($owners)===1&&!in_array($owners[0],[3,6],true))$reasons[]='MANAGER_OUTSIDE_INITIAL_PILOT';
 $body=['client_key'=>$key,'cards'=>array_values($cards)];
 return $body+['cache_revision'=>$cacheRevision,'group_hash'=>hash('sha256',analyticsEvidenceJson($body)),'manager_id'=>count($owners)===1?$owners[0]:null,'reasons'=>array_values(array_unique($reasons)),'stage'=>'CANDIDATE'];
}
function analyticsReviewHistory(array $event,array $history):void {
 if(($history['version']??null)!==1||($history['department']??null)!=='Авиаторов'||($history['normalization_version']??null)!==1||($history['client_key']??null)!==($event['subject']['key']??null)||!is_int($history['sale_count']??null)||$history['sale_count']<1||!preg_match('/^[a-f0-9]{64}$/D',(string)($history['history_hash']??'')))throw new DomainException('Complete stored historical group snapshot required');
}
/** Existing binding and audit tables; saving/revoking never creates tasks. */
function analyticsSaveGroupReview(PDO $db,array $actor,array $event,array $group,array $history,array $input):array {
 managerBindingAdmin($actor);
 $scope=$event['run']['scope']??[];
 if(($scope['policy']??null)!=='aviatorov-wholesale-corporate/2'||($scope['department']??null)!=='Авиаторов'||($scope['buyer_types']??null)!==['Оптовик','Корпоративный'])throw new DomainException('Unapproved historical identity scope');
 $action=$input['action']??'';if(!in_array($action,['begin','confirm','revoke'],true))throw new DomainException('Unknown review action');
 if($action!=='revoke')analyticsReviewHistory($event,$history);
 $key=analyticsSubjectKey($event['subject'],$event['run']['scope']);$db->beginTransaction();
 try{
  // Existing row serializes duplicate confirmation. UI begins before confirm.
  $old=analyticsBinding($db,$key,true);$rev=$old?(int)$old['revision']:0;
  if(($input['expected_revision']??null)!==$rev)throw new TaskConflict('Review revision changed');
  $previous=$old?json_decode($old['evidence'],true,32,JSON_THROW_ON_ERROR):[];
  if($action==='revoke'){
   // Revocation removes authority and must work during source/cache outages.
   // Preserve the last audited snapshot; never invent replacement evidence.
   if(!$old||($previous['origin_subject_key']??null)!==$key)throw new DomainException('Canonical review required for revocation');
   $group=['group_hash'=>$previous['group_hash'],'cache_revision'=>$previous['cache_revision'],'client_key'=>$previous['client_key'],'cards'=>$previous['cards'],'manager_id'=>$previous['manager_id']];
   $history=['history_hash'=>$previous['history_hash'],'sale_count'=>$previous['sale_count'],'last_positive_purchase'=>$previous['last_purchase']];
  }
  $reason=taskText($input['reason']??'',2000);$selected=$input['client_id']??null;
  $status='REVIEW';$stage=$action==='revoke'?'REVOKED':'MANUAL_REVIEW';
  if($action==='confirm'){
   if(!$old||($previous['stage']??null)!=='MANUAL_REVIEW'||$group['reasons']||($previous['group_hash']??null)!==$group['group_hash']||($previous['history_hash']??null)!==$history['history_hash'])throw new DomainException('Group/history changed or not manually reviewed');
   if(($input['confirm_all_history']??null)!==true||($input['confirm_identity']??null)!==true)throw new DomainException('Name/phone suggestions do not verify identity');
   $cards=array_column($group['cards'],null,'client_id');$card=$cards[$selected??'']??null;
   if(!$card||$card['technical']||$card['archived']||$card['manager_id']!==$group['manager_id'])throw new DomainException('Explicit working ClientsVR card required');
   $status='VERIFIED';$stage='VERIFIED';
  }
  if($action!=='confirm')$selected=$old['client_id']??null;
  $data=['stage'=>$stage,'client_id'=>$selected,'reason'=>$reason,'reviewer_id'=>(int)$actor['id'],'group_hash'=>$group['group_hash'],'cache_revision'=>$group['cache_revision'],
   'history_hash'=>$history['history_hash'],'sale_count'=>$history['sale_count'],'last_purchase'=>$history['last_positive_purchase']??null,'client_key'=>$group['client_key'],'scope'=>$event['run']['scope'],
   'cards'=>$group['cards'],'manager_id'=>$group['manager_id'],'reviewed_at'=>gmdate('c'),'origin_subject_key'=>$key];
  $previousIds=$previous['previous_client_ids']??[];
  if(!empty($old['client_id']))$previousIds[]=$old['client_id'];
  $data['previous_client_ids']=array_values(array_unique(array_filter($previousIds,fn($id)=>is_string($id)&&$id!==$selected)));
  $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if(strlen($json)>48000)throw new DomainException('Review exceeds auditable pilot size');
  if($old)$db->prepare('UPDATE analytics_client_bindings SET client_id=?,status=?,technical=0,evidence=?,revision=revision+1,reviewer_id=? WHERE subject_key=?')->execute([$selected,$status,$json,$actor['id'],$key]);
  else $db->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,revision,reviewer_id) VALUES(?,?,?,?,0,?,1,?)')->execute([$key,json_encode(['subject'=>$event['subject'],'scope'=>$event['run']['scope']],JSON_THROW_ON_ERROR),$selected,$status,$json,$actor['id']]);
  if($selected){
   $external=['kind'=>'external_id','version'=>1,'key'=>$selected];$externalKey=analyticsSubjectKey($external,$event['run']['scope']);$other=analyticsBinding($db,$externalKey,true);
   $otherEvidence=$other?json_decode($other['evidence'],true):[];
   if($other&&($otherEvidence['origin_subject_key']??null)!==$key)throw new DomainException('Client ID belongs to another historical group; manual history merge required');
   if($other)$db->prepare('UPDATE analytics_client_bindings SET status=?,evidence=?,revision=?,reviewer_id=? WHERE subject_key=?')->execute([$status,$json,$rev+1,$actor['id'],$externalKey]);
   else $db->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,revision,reviewer_id) VALUES(?,?,?,?,0,?,?,?)')->execute([$externalKey,json_encode(['subject'=>$external,'scope'=>$event['run']['scope']],JSON_THROW_ON_ERROR),$selected,$status,$json,$rev+1,$actor['id']]);
  }
  foreach(array_unique([$key,$externalKey??$key]) as $auditKey)$db->prepare('INSERT INTO analytics_client_binding_events(subject_key,actor_id,data_json) VALUES(?,?,?)')->execute([$auditKey,$actor['id'],json_encode(['action'=>$action,'old_revision'=>$rev,'revision'=>$rev+1,'status'=>$status,'evidence'=>$data],JSON_THROW_ON_ERROR)]);
  $db->commit();return ['status'=>$status,'stage'=>$stage,'revision'=>$rev+1];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function analyticsExportReview(array $binding,array $group,array $history,int $now):array {
 $e=json_decode($binding['evidence'],true,32,JSON_THROW_ON_ERROR);
 require_once __DIR__.'/analytics_preliminary.php';
 $preliminary=($e['stage']??null)==='PRELIMINARY_MATCH'&&$binding['status']==='REVIEW';
 if((!$preliminary&&$binding['status']!=='VERIFIED')||!is_string($e['last_purchase']??null)||($e['client_id']??null)!==$binding['client_id']||$group['reasons']||($e['group_hash']??null)!==$group['group_hash']||($e['history_hash']??null)!==$history['history_hash'])throw new DomainException('Review revoked or group/history changed');
 if($preliminary&&analyticsPreliminaryMatch($group)!==($e['matching']??null))throw new DomainException('Preliminary match changed');
 return analyticsSignReview(['version'=>1,'issuer'=>'calltrack','audience'=>'sales-journal','issued_at'=>gmdate('c',$now),'valid_until'=>gmdate('c',$now+3600),
  'client_key'=>$e['client_key'],'scope'=>$e['scope'],'client_id'=>$binding['client_id'],'manager_id'=>$e['manager_id'],'binding_revision'=>(int)$binding['revision'],
  'history_hash'=>$e['history_hash'],'sale_count'=>$e['sale_count'],'group_hash'=>$e['group_hash'],'cache_revision'=>$group['cache_revision'],
  'cards'=>$e['cards'],
  'last_purchase'=>$e['last_purchase'],
  'reviewer_id'=>(int)$binding['reviewer_id'],'reviewed_at'=>$e['reviewed_at'],'reason'=>$e['reason'],'status'=>$preliminary?'PRELIMINARY_MATCH':'VERIFIED']+($preliminary?['matching'=>analyticsPreliminaryMatch($group)]:[]));
}
function analyticsExportReviewRefusal(array $binding,int $now):array {
 $e=json_decode($binding['evidence'],true,32,JSON_THROW_ON_ERROR);
 if($binding['status']==='VERIFIED'||($e['origin_subject_key']??null)!==$binding['subject_key'])throw new DomainException('Canonical non-authorizing review state required');
 return analyticsSignReview(['version'=>1,'issuer'=>'calltrack','audience'=>'sales-journal','issued_at'=>gmdate('c',$now),'valid_until'=>gmdate('c',$now+3600),
  'client_key'=>$e['client_key'],'scope'=>$e['scope'],'client_id'=>$binding['client_id'],'manager_id'=>$e['manager_id']??0,
  'binding_revision'=>(int)$binding['revision'],'reviewer_id'=>(int)$binding['reviewer_id'],'status'=>'REVIEW','reason'=>$e['reason'],'workflow_stage'=>$e['stage']]);
}
