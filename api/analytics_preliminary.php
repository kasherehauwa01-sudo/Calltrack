<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_verified_bindings.php';
const ANALYTICS_PRELIMINARY_POLICY='preliminary-customer-group/2';
/** Pure, deterministic matching. No advisory report is an input or authority. */
function analyticsPreliminaryMatch(array $group):array {
 $deny=static fn(string $reason)=>['status'=>'BLOCKED','reasons'=>[$reason]];
 if($group['reasons'])return $deny(count(array_unique(array_filter(array_column($group['cards'],'manager_id'))))>1?'MANAGER_CONFLICT':$group['reasons'][0]);
 foreach($group['cards'] as $card)if(!$card['archived']&&$card['technical']&&!in_array($card['customer_kind']??null,['TECHNICAL','AGGREGATE'],true))return $deny('CUSTOMER_CLASSIFICATION_UNCONFIRMED');
 $working=array_values(array_filter($group['cards'],fn($c)=>!$c['technical']&&!$c['archived']));
 if(!$working)return $deny('NO_WORKING_CUSTOMER');
 foreach($working as $card)if(($card['customer_kind']??null)!=='CUSTOMER')return $deny('CUSTOMER_CLASSIFICATION_UNCONFIRMED');
 if(!in_array($group['manager_id']??null,[3,6],true))return $deny('MANAGER_OUTSIDE_INITIAL_PILOT');
 if(!is_string($group['client_key']??null)||$group['client_key']===''||analyticsNormalizeNameV1($group['client_key'])!==$group['client_key'])return $deny('INVALID_NORMALIZED_NAME');
 foreach($working as $card)if(!is_string($card['client_id']??null)||$card['client_id']===''||($card['manager_id']??null)!==$group['manager_id'])return $deny('MANAGER_CONFLICT');
 $ids=array_column($working,'client_id');sort($ids,SORT_STRING);
 if(count($ids)!==count(array_unique($ids)))return $deny('DUPLICATE_CARD_ID');
 $hasAuthority=false;foreach($working as $card)if(array_key_exists('customer_identity',$card)||array_key_exists('primary_client_id',$card))$hasAuthority=true;
 if(!$hasAuthority){$primary=$ids[0];$identity='clientsvr-name-group:v1:aviatorov:'.$group['client_key'];$method='EXACT_NAME_SINGLE_MANAGER';}
 else{
  foreach($working as $card){if(!is_string($card['customer_identity']??null)||trim($card['customer_identity'])==='')return $deny('GROUP_IDENTITY_UNCONFIRMED');if(!is_string($card['primary_client_id']??null)||trim($card['primary_client_id'])==='')return $deny('PRIMARY_CARD_UNCONFIRMED');}
  $identities=array_unique(array_column($working,'customer_identity'));
  $primaries=array_unique(array_column($working,'primary_client_id'));
  if(count($identities)!==1||!is_string($identities[0])||trim($identities[0])==='')return $deny('GROUP_IDENTITY_UNCONFIRMED');
  if(count($primaries)!==1||!is_string($primaries[0])||!in_array($primaries[0],$ids,true))return $deny('PRIMARY_CARD_UNCONFIRMED');
  $identity='clientsvr-customer:'.$identities[0];$primary=$primaries[0];$method='PRIMARY_CUSTOMER_GROUP';
 }
 return ['status'=>'PRELIMINARY_MATCH','policy'=>ANALYTICS_PRELIMINARY_POLICY,'method'=>$method,
  'group_key'=>hash('sha256',$identity),'customer_identity'=>$identity,'client_id'=>$primary,
  'member_ids'=>$ids,'manager_id'=>$group['manager_id'],'manual_verified'=>false,
  'basis'=>['exact_normalized_name'=>$group['client_key'],'full_card_group_hash'=>$group['group_hash'],'working_card_ids'=>$ids]];
}
function analyticsBindingAssignable(?array $binding):bool {
 if(!$binding)return false;if($binding['status']==='VERIFIED')return true;
 $e=json_decode($binding['evidence'],true);return $binding['status']==='REVIEW'&&($e['stage']??null)==='PRELIMINARY_MATCH';
}
/** Explicit preparation only; never mutates existing human decisions or creates tasks. */
function analyticsPreparePreliminary(PDO $db,array $event,array $group,array $history,bool $apply=false):array {
 analyticsReviewHistory($event,$history);$match=analyticsPreliminaryMatch($group);
 $scope=$event['run']['scope'];
 if(($scope['policy']??null)!=='aviatorov-wholesale-corporate/2'||($scope['department']??null)!=='Авиаторов'||($scope['buyer_types']??null)!==['Оптовик','Корпоративный'])throw new DomainException('Unapproved matching scope');
 $existing=analyticsBinding($db,analyticsSubjectKey($event['subject'],$scope));
 if(!$apply&&$existing){$e=json_decode($existing['evidence'],true);if($existing['status']!=='REVIEW'||($e['stage']??null)!=='PRELIMINARY_MATCH')return ['status'=>'BLOCKED','reasons'=>['EXISTING_ADMIN_DECISION'],'persisted'=>false];}
 if($match['status']!=='PRELIMINARY_MATCH'||!$apply)return $match+['persisted'=>false];
 $key=analyticsSubjectKey($event['subject'],$scope);$external=['kind'=>'external_id','version'=>1,'key'=>$match['client_id']];$otherKey=analyticsSubjectKey($external,$scope);
 $db->beginTransaction();try{
  $old=analyticsBinding($db,$key,true);$other=analyticsBinding($db,$otherKey,true);$prior=$old?json_decode($old['evidence'],true,64,JSON_THROW_ON_ERROR):[];
  if($old&&($old['status']!=='REVIEW'||($prior['stage']??null)!=='PRELIMINARY_MATCH'))throw new DomainException('EXISTING_ADMIN_DECISION');
  if($other){$oe=json_decode($other['evidence'],true,64,JSON_THROW_ON_ERROR);if(($oe['origin_subject_key']??null)!==$key||!analyticsBindingAssignable($other)||$other['status']==='VERIFIED')throw new DomainException('EXISTING_ADMIN_OR_OTHER_GROUP');}
  if($old&&$old['client_id']!==$match['client_id'])throw new DomainException('PRIMARY_CHANGED_REQUIRES_ADMIN');
  $data=['stage'=>'PRELIMINARY_MATCH','matching'=>$match,'client_id'=>$match['client_id'],'client_key'=>$group['client_key'],'scope'=>$scope,
   'origin_subject_key'=>$key,'group_hash'=>$group['group_hash'],'cache_revision'=>$group['cache_revision'],'cards'=>$group['cards'],
   'manager_id'=>$match['manager_id'],'history_hash'=>$history['history_hash'],'sale_count'=>$history['sale_count'],'last_purchase'=>$history['last_positive_purchase'],
   'reason'=>'Automatic preliminary match; not a human identity confirmation','reviewer_id'=>0,'reviewed_at'=>null];
  $core=static function($value){unset($value['cache_revision']);return $value;};
  if($old&&$core($prior)==$core($data)){$db->rollBack();return $match+['persisted'=>false,'existing'=>true];}
  $revision=$old?(int)$old['revision']+1:1;$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>48000)throw new DomainException('Matching evidence exceeds bound');
  foreach([[$key,$event['subject']],[$otherKey,$external]] as [$subjectKey,$subject]){
   $existing=analyticsBinding($db,$subjectKey,true);
   if($existing)$db->prepare('UPDATE analytics_client_bindings SET client_id=?,status=\'REVIEW\',technical=0,evidence=?,revision=?,reviewer_id=0 WHERE subject_key=?')->execute([$match['client_id'],$json,$revision,$subjectKey]);
   else $db->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,revision,reviewer_id) VALUES(?,?,?,\'REVIEW\',0,?,?,0)')->execute([$subjectKey,json_encode(['subject'=>$subject,'scope'=>$scope],JSON_THROW_ON_ERROR),$match['client_id'],$json,$revision]);
   $db->prepare('INSERT INTO analytics_client_binding_events(subject_key,actor_id,data_json) VALUES(?,0,?)')->execute([$subjectKey,json_encode(['action'=>'PRELIMINARY_MATCH','revision'=>$revision,'previous_revision'=>$old?(int)$old['revision']:0,'evidence'=>$data],JSON_THROW_ON_ERROR)]);
  }
  $db->commit();return $match+['persisted'=>true,'revision'=>$revision];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
