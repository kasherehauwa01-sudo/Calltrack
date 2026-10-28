<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_preliminary.php';
require_once __DIR__.'/analytics_source_trust.php';
/** Cryptographic proof augments, never replaces, the existing gate. */
function analyticsVerifiedApproval(array $event,?array $binding,array $managers,array $policy,int $now):array {
 $stage='UPSTREAM_SIGNED_EVIDENCE_INVALID';
 try{
  $sourceEnvelope=$event['upstream_manifest']??[];$receiptEnvelope=$event['binding_receipt']??[];
  $age=$policy['max_manifest_age_seconds']??null;if(!is_int($age)||$age<=0)throw new DomainException('Manifest age not approved');
  $source=analyticsVerifyEvidence($sourceEnvelope,analyticsEvidenceKeys('CALLTRACK_UPSTREAM_PUBLIC_KEYS'),(string)($policy['upstream_issuer']??''),'sales-journal+calltrack',$age,$now);
  $contract=analyticsSourceTrustContract($policy,$source);
  foreach($contract as $key=>$value)if(($event['run']['source_decision'][$key]??null)!==$value)throw new DomainException('Source trust declaration differs');
  $stage='CLIENTS_CACHE_REVISION_CHANGED';
  if(($policy['cache_revision']??null)!==clientCatalogRevision())throw new DomainException('Directory cache changed since policy snapshot');
  $stage='BINDING_SIGNED_EVIDENCE_INVALID';
  $receipt=analyticsVerifyEvidence($receiptEnvelope,analyticsEvidenceKeys('CALLTRACK_BINDINGS_PUBLIC_KEYS'),'calltrack','sales-journal',3600,$now);
  if(!is_array($receipt['cards']??null)||!array_is_list($receipt['cards'])||!$receipt['cards']||count($receipt['cards'])>500
    ||hash('sha256',analyticsEvidenceJson(['client_key'=>$receipt['client_key']??null,'cards'=>$receipt['cards']]))!==($receipt['group_hash']??null))throw new DomainException('Incomplete reviewed card projection');
  $stage='REVIEW_OR_ASSIGNMENT_REVOKED_OR_CHANGED';
  $run=$event['run'];$id=$event['subject']['key'];$e=$binding?json_decode($binding['evidence'],true,32,JSON_THROW_ON_ERROR):[];
  $preliminary=($receipt['status']??null)==='PRELIMINARY_MATCH';
  if(!analyticsBindingAssignable($binding)||($preliminary?($binding['status']!=='REVIEW'||($e['stage']??null)!=='PRELIMINARY_MATCH'):(($e['stage']??null)!=='VERIFIED'||($receipt['status']??null)!=='VERIFIED'))
   ||($receipt['client_id']??null)!==$id||$id!==$binding['client_id']
   ||($receipt['binding_revision']??null)!==(int)$binding['revision']||($event['assignment']['binding_revision']??null)!==(int)$binding['revision']
   ||($receipt['manager_id']??null)!==($event['assignment']['manager_id']??null)||!in_array($receipt['manager_id']??null,[3,6],true)
   ||($receipt['last_purchase']??null)!==$event['last_purchase']
   ||($receipt['scope']??null)!=$run['scope']||($receipt['history_hash']??null)!==($e['history_hash']??null)
   ||($receipt['group_hash']??null)!==($e['group_hash']??null))throw new DomainException('Review revoked or assignment changed');
  $stage='CLIENT_GROUP_OR_MANAGER_CHANGED';
  $groupEvent=$event;$groupEvent['subject']=['kind'=>'normalized_name','version'=>1,'key'=>$receipt['client_key']];
  $group=analyticsCurrentReviewGroup($groupEvent,$managers,$policy['cache_revision']);
  if($preliminary&&count(array_unique(array_filter(array_column($group['cards'],'manager_id'))))>1)$stage='MANAGER_CONFLICT';
  if($group['reasons']||$group['group_hash']!==$receipt['group_hash']||$group['manager_id']!==$receipt['manager_id'])throw new DomainException('Directory group/manager changed');
  if($preliminary&&(analyticsEvidenceJson($receipt['matching']??[])!==analyticsEvidenceJson(analyticsPreliminaryMatch($group))||analyticsEvidenceJson($e['matching']??[])!==analyticsEvidenceJson($receipt['matching'])))throw new DomainException('Preliminary evidence changed');
  if($preliminary&&analyticsEvidenceJson($event['matching']??[])!==analyticsEvidenceJson($receipt['matching']??[]))throw new DomainException('Unsigned matching metadata differs');
  if(!$preliminary&&isset($event['matching']))throw new DomainException('Unexpected preliminary metadata on manual approval');
  if($preliminary&&($source['matching_policy']??null)!==ANALYTICS_PRELIMINARY_POLICY)throw new DomainException('Primary manifest does not cover preliminary policy');
  $stage='UPSTREAM_PROOF_CONTEXT_MISMATCH';$hash=hash('sha256',$sourceEnvelope['payload_json']);
  $required=$policy['required_sources']??[];$revisions=$source['source_revisions']??null;
  if(!is_array($required)||!array_is_list($required)||!$required||array_filter($required,fn($value)=>!analyticsKnownEvidenceText($value))||count(array_unique($required))!==count($required)||!is_array($revisions))throw new DomainException('Required primary sources not approved');
  $actual=array_keys($revisions);$expected=$required;sort($actual);sort($expected);
  if($actual!==$expected||array_filter($revisions,fn($revision)=>!analyticsKnownEvidenceText($revision)))throw new DomainException('Incomplete primary source set');
  if(!is_int($source['fact_count']??null)||$source['fact_count']<1||!preg_match('/^[a-f0-9]{64}$/D',(string)($source['facts_hash']??'')))throw new DomainException('Missing primary facts evidence');
  if(!is_array($source['assignments']??null)||!array_is_list($source['assignments'])||!is_array($source['review_groups']??null)||!array_is_list($source['review_groups'])
    ||!is_int($source['candidate_count']??null)||!is_int($source['reviewed_count']??null)||$source['reviewed_count']!==count($source['assignments'])||$source['reviewed_count']!==count($source['review_groups'])||$source['candidate_count']<$source['reviewed_count'])throw new DomainException('Incomplete reviewed candidate set');
  $groupProofs=array_values(array_filter($source['review_groups'],fn($g)=>is_array($g)&&($g['client_id']??null)===$id));
  if(count($groupProofs)!==1||analyticsEvidenceJson($groupProofs[0])!==analyticsEvidenceJson(['client_id'=>$id,'group_hash'=>$receipt['group_hash']]))throw new DomainException('Primary ClientsVR group not independently confirmed');
  if(($source['clients_current']??null)!==true||!analyticsKnownEvidenceText($source['clients_revision']??null)
   ||($source['clients_cache_revision']??null)!==$receipt['cache_revision']||($source['complete_through']??null)!==$event['as_of']||($source['as_of']??null)!==$event['as_of']
   ||($source['source_revision']??null)!==($run['source_revision']??null)||($source['source_run_id']??null)!==($event['provenance']['shadow_run_id']??null)
   ||($source['public_scope_hash']??null)!==hash('sha256',analyticsEvidenceJson($run['scope']))
   ||($run['source_decision']['proof_hash']??null)!==$hash||($event['provenance']['proof_hash']??null)!==$hash)throw new DomainException('Source proof differs from event');
  $assignment=['subject_hash'=>hash('sha256',json_encode($receipt['client_key'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
    'client_id'=>$id,'manager_id'=>$receipt['manager_id'],'revision'=>$receipt['binding_revision']];
  if(!is_array($source['assignments']??null)||!in_array($assignment,$source['assignments'],true)){
   // Associative JSON order is not identity; compare canonical forms strictly.
   $found=false;foreach($source['assignments']??[] as $candidate)if(is_array($candidate)&&analyticsEvidenceJson($candidate)===analyticsEvidenceJson($assignment))$found=true;
   if(!$found)throw new DomainException('Manifest does not cover this assignment');
  }
  $facts=['upstream_confirmed'=>true,'complete_through'=>$source['complete_through'],'verified_at'=>$source['issued_at'],'valid_until'=>$source['valid_until'],'source_revision'=>(string)$source['source_revision']];
  // Existing explicit local refusals remain additional gates, never overwritten.
  if(!array_key_exists('sales',$policy))$policy['sales']=$facts;
  if(array_key_exists('clientsvr',$policy)&&($policy['clientsvr']['source_revision']??null)!==$source['clients_revision'])throw new DomainException('Local client source revision differs');
  if(!array_key_exists('clientsvr',$policy))$policy['clientsvr']=array_replace($facts,['cache_revision'=>$policy['cache_revision'],'source_revision'=>$source['clients_revision']]);
  return ['reasons'=>[],'policy'=>$policy];
 }catch(Throwable $e){return ['reasons'=>['SIGNED_SOURCE_OR_REVIEW_INVALID',$stage],'policy'=>$policy];}
}
