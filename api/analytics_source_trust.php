<?php
declare(strict_types=1);
/** No UNKNOWN is promoted: owner-declared trust has its own signed protocol. */
function analyticsSourceTrustContract(array $policy,?array $manifest=null):array {
 $mode=$policy['source_trust_mode']??'INDEPENDENT';
 if(!in_array($mode,['INDEPENDENT','TRUSTED_IMPORT'],true))throw new DomainException('Unknown source trust mode');
 $approval=$policy['import_trust_approval_id']??null;
 if($mode==='TRUSTED_IMPORT'&&(!is_string($approval)||trim($approval)===''||in_array(strtoupper(trim($approval)),['UNKNOWN','UNSET','NULL','NONE'],true)))throw new DomainException('Explicit import trust approval required');
 if($manifest!==null){
  if($mode==='TRUSTED_IMPORT'){
   if(($manifest['source_trust_mode']??null)!==$mode||($manifest['import_trust_approval_id']??null)!==$approval||($manifest['complete']??null)!==false||!is_string($manifest['as_of']??null)||($manifest['declared_complete_through']??null)!==$manifest['as_of'])throw new DomainException('Invalid trusted import declaration');
  }elseif(($manifest['source_trust_mode']??'INDEPENDENT')!=='INDEPENDENT'||($manifest['complete']??null)!==true)throw new DomainException('Independent completeness required');
 }
 return ['method'=>$mode==='TRUSTED_IMPORT'?'OWNER_TRUSTED_IMPORT_SIGNED_V1':'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','coverage_status'=>$mode==='TRUSTED_IMPORT'?'DECLARED':'CONFIRMED','business_freshness'=>$mode==='TRUSTED_IMPORT'?'DECLARED_CURRENT':'CURRENT'];
}
