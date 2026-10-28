<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_verified_bindings.php';
require_once __DIR__.'/fixtures/analytics/database.php';
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1')throw new RuntimeException('Synthetic fixtures only');
$n=0;function reviewCheck(bool $ok,string $message):void{global $n;$n++;if(!$ok)throw new RuntimeException($message);}
function reviewDenied(callable $action,string $why):void{try{$action();}catch(DomainException|TaskConflict|InvalidArgumentException $e){reviewCheck(true,$why);return;}throw new RuntimeException('Expected refusal: '.$why);}
$db=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];saveManagerBinding($db,$admin,'Owner',3,null);saveManagerBinding($db,$admin,'Other',4,null);
$event=['subject'=>['kind'=>'normalized_name','version'=>1,'key'=>'test buyer'],'run'=>['scope'=>['policy'=>'aviatorov-wholesale-corporate/2','department'=>'Авиаторов','buyer_types'=>['Оптовик','Корпоративный']]]];
$row=fn($id,$name,$owner)=>['id'=>$id,'name'=>$name,'phones'=>[],'fields'=>['Менеджер'=>$owner]];
$rows=[$row('working',' TEST\u{00a0} BUYER ','Owner')];$rows[0]['name']=" TEST\u{00a0} BUYER ";
$bindings=managerBindingRows($db);$group=analyticsReviewGroup($event,$rows,$bindings,'cache');
reviewCheck(!$group['reasons']&&$group['manager_id']===3,'One buyer, no phone, one manager');
$rows[]=$row('second','Test Buyer','Owner');$rows[]=$row('technical','test buyer','');
$group=analyticsReviewGroup($event,$rows,$bindings,'cache');reviewCheck(!$group['reasons']&&count($group['cards'])===3,'Several working cards, one owner, technical card');
$refresh=analyticsReviewGroup($event,$rows,$bindings,'unrelated-cache-refresh');reviewCheck($refresh['group_hash']===$group['group_hash'],'Unrelated refresh does not change reviewed identity semantics');
$conflict=$rows;$conflict[]=$row('conflict','test buyer','Other');reviewCheck((bool)analyticsReviewGroup($event,$conflict,$bindings,'cache')['reasons'],'Different owners conflict');
reviewDenied(fn()=>analyticsReviewGroup($event,[$rows[0],$rows[0]],$bindings,'cache'),'Duplicate stable ID');
$history=['version'=>1,'department'=>'Авиаторов','normalization_version'=>1,'client_key'=>'test buyer','sale_count'=>3,'history_hash'=>str_repeat('a',64),'last_positive_purchase'=>'2026-09-01'];
$request=['action'=>'confirm','expected_revision'=>0,'client_id'=>'working','reason'=>'Synthetic full historical review','confirm_all_history'=>true,'confirm_identity'=>true];
reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$group,$history,$request),'Cannot jump candidate to verified');
reviewDenied(fn()=>analyticsSaveGroupReview($db,['id'=>3,'role'=>'manager'],$event,$group,$history,$request),'Non-admin forbidden');
$begin=analyticsSaveGroupReview($db,$admin,$event,$group,$history,array_replace($request,['action'=>'begin']));reviewCheck($begin['stage']==='MANUAL_REVIEW','Explicit manual review');
$request['expected_revision']=1;
reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$group,$history,array_replace($request,['confirm_identity'=>false])),'Name/phone not proof');
reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$group,$history,array_replace($request,['client_id'=>'technical'])),'Cannot select technical card');
$changed=$group;$changed['group_hash']=str_repeat('c',64);reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$changed,$history,$request),'Composition change requires new review');
$changedHistory=$history;$changedHistory['history_hash']=str_repeat('b',64);reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$group,$changedHistory,$request),'Historical group change');
$confirmed=analyticsSaveGroupReview($db,$admin,$event,$group,$history,$request);reviewCheck($confirmed['status']==='VERIFIED'&&$confirmed['revision']===2,'Explicit verified client/version');
reviewDenied(fn()=>analyticsSaveGroupReview($db,$admin,$event,$group,$history,$request),'Stale revision');
$external=analyticsSubjectKey(['kind'=>'external_id','version'=>1,'key'=>'working'],$event['run']['scope']);$binding=analyticsBinding($db,$external);
reviewCheck($binding['status']==='VERIFIED'&&(int)$binding['revision']===2,'External ID preserves reviewed binding version');
$secret=sodium_crypto_sign_keypair();$file=tempnam('/tmp','review-test-key-');chmod($file,0600);file_put_contents($file,base64_encode(sodium_crypto_sign_secretkey($secret)));putenv('CALLTRACK_BINDINGS_SIGNING_KEY_FILE='.$file);putenv('CALLTRACK_BINDINGS_SIGNING_KEY_ID=fixture');
try{
 $now=time();$receipt=analyticsExportReview($binding,$group,$history,$now);$keys=['fixture'=>base64_encode(sodium_crypto_sign_publickey($secret))];
 $p=analyticsVerifyEvidence($receipt,$keys,'calltrack','sales-journal',3600,$now);reviewCheck($p['client_id']==='working'&&$p['manager_id']===3,'Signed review covers selected client and owner');
 $bad=$receipt;$bad['payload_json'].=' ';reviewDenied(fn()=>analyticsVerifyEvidence($bad,$keys,'calltrack','sales-journal',3600,$now),'Tampered JSON');
 reviewDenied(fn()=>analyticsVerifyEvidence($receipt,$keys,'calltrack','sales-journal',3600,$now+3601),'Expired review');
 reviewDenied(fn()=>analyticsExportReview($binding,$changed,$history,$now),'Changed group not exportable');
 analyticsSaveGroupReview($db,$admin,$event,[],[],array_replace($request,['expected_revision'=>2,'action'=>'revoke']));
 reviewCheck(analyticsBinding($db,$external)['status']==='REVIEW','Source/cache outage cannot prevent revocation');
 reviewDenied(fn()=>analyticsExportReview(analyticsBinding($db,$external),$group,$history,$now),'Revocation blocks export');
 reviewCheck((int)$db->query('SELECT COUNT(*) FROM analytics_client_binding_events')->fetchColumn()===5,'Every normalized/external review mutation audited');
 reviewCheck((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Review/export/revoke never create tasks');
 $task=['evidence_json'=>json_encode(['signal'=>['signal_id'=>'own-signal','assignment'=>['client_id'=>'own-client','manager_id'=>3],'metrics'=>['median_interval'=>5],'upstream_manifest'=>['other_managers'=>['private-client']],'binding_receipt'=>['private'=>'bundle']]])];
 $visible=json_decode(taskEvidenceForViewer($task,['id'=>3,'role'=>'manager'])['evidence_json'],true);
 reviewCheck(!isset($visible['signal']['upstream_manifest'])&&!isset($visible['signal']['binding_receipt'])&&$visible['signal']['assignment']['client_id']==='own-client','Manager cannot see other-client proof bundles');
 reviewCheck(taskEvidenceForViewer($task,$admin)===$task,'Admin keeps full audit evidence');
}finally{unlink($file);}
echo 'PASS verified review: '.$n." assertions\n";
