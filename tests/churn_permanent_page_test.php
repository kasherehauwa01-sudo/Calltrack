<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/../api/analytics_permanent.php';
function mustReject(callable $fn):void{
 try{$fn();}catch(DomainException|InvalidArgumentException|JsonException $e){return;}
 throw new RuntimeException('Invalid page accepted');
}
$day='2026-11-20';
$event=['source'=>'sales-journal','signal_id'=>'page-signal','revision'=>1,'status'=>'ACTIVE','signal_type'=>'CHURN_RISK','family'=>'INACTIVITY',
 'subject'=>['kind'=>'external_id','version'=>1,'key'=>'synthetic-client'],'score'=>70.0,'confidence'=>1.0,'as_of'=>$day,'last_purchase'=>'2026-09-01',
 'mode'=>'AUTOMATION_APPROVED','calculated_at'=>'2026-11-21T07:00:00Z','automation_allowed'=>true,'metrics'=>[],
 'run'=>['id'=>'release-test','scope'=>[]],'permanent_policy'=>[]];
$raw=json_encode($event,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
$item=['event_id'=>1,'event_hash'=>hash('sha256',$raw),'event_json'=>$raw];
$page=['job_id'=>'job-test','release_id'=>'release-test','events'=>[$item]];
if(count(analyticsPermanentValidatePage($page))!==1)throw new RuntimeException('Valid exact-byte page rejected');
foreach(['duplicate','hash','id','release','extra','size','json'] as $failure){
 $bad=$page;
 if($failure==='duplicate')$bad['events'][]=$item;
 if($failure==='hash')$bad['events'][0]['event_hash']=str_repeat('0',64);
 if($failure==='id')$bad['events'][0]['event_id']='1';
 if($failure==='release')$bad['release_id']='different';
 if($failure==='extra')$bad['untrusted']=true;
 if($failure==='size')$bad['events']=array_fill(0,26,$item);
 if($failure==='json'){$bad['events'][0]['event_json']='{';$bad['events'][0]['event_hash']=hash('sha256','{');}
 mustReject(fn()=>analyticsPermanentValidatePage($bad));
}
echo "churn_permanent_page_test: exact bytes, context, bounds, duplicates and malformed JSON OK\n";
