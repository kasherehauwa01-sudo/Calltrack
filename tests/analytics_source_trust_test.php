<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_source_trust.php';
$p=['source_trust_mode'=>'TRUSTED_IMPORT','import_trust_approval_id'=>'synthetic-decision'];
$m=$p+['as_of'=>'2026-10-25','declared_complete_through'=>'2026-10-25','complete'=>false];
$c=analyticsSourceTrustContract($p,$m);
if($c!==['method'=>'OWNER_TRUSTED_IMPORT_SIGNED_V1','coverage_status'=>'DECLARED','business_freshness'=>'DECLARED_CURRENT'])throw new RuntimeException('Misrepresented trust');
foreach([['source_trust_mode','INDEPENDENT'],['import_trust_approval_id','other'],['complete',true],['complete',null],['declared_complete_through','2026-10-24']] as [$field,$value]){
 $bad=$m;$bad[$field]=$value;
 try{analyticsSourceTrustContract($p,$bad);throw new RuntimeException('Accepted invalid declaration');}catch(DomainException $e){}
}
try{analyticsSourceTrustContract([],$m);throw new RuntimeException('Trusted manifest accepted by independent policy');}catch(DomainException $e){}
foreach([null,'','UNKNOWN',true] as $approval){try{analyticsSourceTrustContract(['source_trust_mode'=>'TRUSTED_IMPORT','import_trust_approval_id'=>$approval]);throw new RuntimeException('No approval');}catch(DomainException $e){}}
echo "PASS explicit source trust: no fake independent completeness, mismatches and missing approval blocked\n";
