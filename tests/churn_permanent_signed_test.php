<?php
declare(strict_types=1);
/** Real Ed25519 -> existing gate -> patched task transaction, synthetic SQLite only. */
require __DIR__.'/churn_permanent_budget_test.php';
require_once $root.'/api/analytics_integration.php';
require_once $root.'/api/analytics_verified_bindings.php';
$storage=sys_get_temp_dir().'/calltrack-churn-test-'.bin2hex(random_bytes(8));mkdir($storage,0700);
putenv('CALLTRACK_STORAGE_DIR='.$storage);
$directory=new PDO('sqlite:'.clientsSqliteFile());
$directory->exec('CREATE TABLE clients(client_id TEXT PRIMARY KEY,payload_json TEXT)');
$card=['id'=>'signed-client','name'=>'Synthetic Customer','phones'=>[], 'fields'=>['manager'=>'Signed Owner','status'=>'active']];
$directory->prepare('INSERT INTO clients VALUES(?,?)')->execute([$card['id'],json_encode($card,JSON_THROW_ON_ERROR)]);
$directory=null;
$admin=['id'=>1,'role'=>'admin'];saveManagerBinding($db,$admin,'Signed Owner',3,null);
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec("INSERT INTO web_users(id,display_name,role,is_active,login,pin_hash) VALUES(6,'Synthetic alternate manager','manager',1,'fixture6@example.test','synthetic-disabled-login')");
else $db->exec("INSERT INTO web_users VALUES(6,'Synthetic alternate manager','manager',1)");
$managers=managerBindingRows($db);$cache=clientCatalogRevision();
$scope=['policy'=>'aviatorov-wholesale-corporate/2','department'=>'Авиаторов','buyer_types'=>['Оптовик','Корпоративный']];
$nameEvent=['subject'=>['kind'=>'normalized_name','version'=>1,'key'=>'synthetic customer'],'run'=>['scope'=>$scope]];
$group=analyticsReviewGroup($nameEvent,[$card],$managers,$cache);
$history=['version'=>1,'department'=>'Авиаторов','normalization_version'=>1,'client_key'=>'synthetic customer',
 'sale_count'=>3,'history_hash'=>hash('sha256','independent synthetic purchase fixture'),'last_positive_purchase'=>'2026-07-01'];
$begin=analyticsSaveGroupReview($db,$admin,$nameEvent,$group,$history,['action'=>'begin','reason'=>'Synthetic independent review','expected_revision'=>0]);
$confirm=analyticsSaveGroupReview($db,$admin,$nameEvent,$group,$history,['action'=>'confirm','reason'=>'Synthetic independent review','expected_revision'=>$begin['revision'],
 'client_id'=>'signed-client','confirm_all_history'=>true,'confirm_identity'=>true]);
$subject=['kind'=>'external_id','version'=>1,'key'=>'signed-client'];
$binding=analyticsBinding($db,analyticsSubjectKey($subject,$scope));
$upstream=sodium_crypto_sign_keypair();$bindings=sodium_crypto_sign_keypair();
$keyfile=$storage.'/bindings.key';file_put_contents($keyfile,base64_encode(sodium_crypto_sign_secretkey($bindings)));chmod($keyfile,0600);
putenv('CALLTRACK_BINDINGS_SIGNING_KEY_FILE='.$keyfile);putenv('CALLTRACK_BINDINGS_SIGNING_KEY_ID=synthetic-bindings');
putenv('CALLTRACK_UPSTREAM_PUBLIC_KEYS='.json_encode(['synthetic-upstream'=>base64_encode(sodium_crypto_sign_publickey($upstream))]));
putenv('CALLTRACK_BINDINGS_PUBLIC_KEYS='.json_encode(['synthetic-bindings'=>base64_encode(sodium_crypto_sign_publickey($bindings))]));
$now=time();$day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->modify('-1 day')->format('Y-m-d');
$receipt=analyticsExportReview($binding,$group,$history,$now);
$source=['version'=>1,'issuer'=>'synthetic-independent-source','audience'=>'sales-journal+calltrack',
 'issued_at'=>gmdate('c',$now-1),'valid_until'=>gmdate('c',$now+600),
 'source_run_id'=>'synthetic-source-run','source_revision'=>7,'as_of'=>$day,'complete_through'=>$day,'complete'=>true,
 'clients_current'=>true,'clients_revision'=>'synthetic-clients-revision','clients_cache_revision'=>$cache,
 'source_revisions'=>['synthetic-source'=>'fixture-revision'],'fact_count'=>3,'facts_hash'=>hash('sha256','three independent purchase facts'),
 'public_scope_hash'=>hash('sha256',analyticsEvidenceJson($scope)),
 'assignments'=>[['subject_hash'=>hash('sha256',json_encode('synthetic customer',JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
 'client_id'=>'signed-client','manager_id'=>3,'revision'=>(int)$binding['revision']]],
 'review_groups'=>[['client_id'=>'signed-client','group_hash'=>$group['group_hash']]],'candidate_count'=>1,'reviewed_count'=>1];
$raw=analyticsEvidenceJson($source);
$manifest=['algorithm'=>'Ed25519','key_id'=>'synthetic-upstream','payload_json'=>$raw,
 'signature'=>base64_encode(sodium_crypto_sign_detached($raw,sodium_crypto_sign_secretkey($upstream)))];
$hash=hash('sha256',$raw);
$sourceDecision=['method'=>'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','coverage_status'=>'CONFIRMED','business_freshness'=>'CURRENT',
 'accepted_data_through'=>$day,'effective_as_of'=>$day,'source_revision'=>7,'proof_hash'=>$hash,'policy_revision'=>3,'valid_until'=>$source['valid_until']];
$event=['source'=>'sales-journal','signal_id'=>'signed-signal','revision'=>1,'status'=>'ACTIVE','family'=>'INACTIVITY','signal_type'=>'CHURN_RISK',
 'subject'=>$subject,'assignment'=>['client_id'=>'signed-client','manager_id'=>3,'binding_revision'=>(int)$binding['revision']],
 'score'=>70.0,'confidence'=>0.8,'last_purchase'=>'2026-07-01','as_of'=>$day,'calculated_at'=>gmdate('c',$now),
 'mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'analytical_eligible'=>true,'generator_version'=>'inactivity/aviator-churn-pilot/2',
 'metrics'=>['median_interval'=>90.0,'days_without_purchase'=>103],
 'run'=>['id'=>'synthetic-release','as_of'=>$day,'scope'=>$scope,'source_revision'=>7,'automation_allowed'=>true,'source_decision'=>$sourceDecision],
 'provenance'=>['shadow_run_id'=>'synthetic-source-run','proof_hash'=>$hash], 'upstream_manifest'=>$manifest,'binding_receipt'=>$receipt,
 'permanent_policy'=>['version'=>'churn-permanent/1','revision'=>2,'producer_revision'=>2,'daily_limit'=>2,'cooldown_days'=>30]];
$policy=['upstream_issuer'=>'synthetic-independent-source','max_manifest_age_seconds'=>3600,'required_sources'=>['synthetic-source'],
 'cache_revision'=>$cache,'approved_scope_policies'=>[$scope['policy']],
 'approved_scope_hashes'=>[hash('sha256',json_encode(analyticsCanonical($scope),JSON_UNESCAPED_UNICODE))],
 'approved_generators'=>['inactivity/aviator-churn-pilot/2'],'max_signal_age_seconds'=>86400,
 'min_confidence'=>0.5,'priorities'=>['CHURN_RISK'=>'HIGH'],'due_days_by_type'=>['CHURN_RISK'=>3],
 'policy_approved'=>true,'enabled_types'=>['CHURN_RISK']];
$client=['id'=>'signed-client','manager'=>'Signed Owner','manager_conflict'=>false,'status'=>'active'];
saveAnalyticsPermanentPolicy($db,['action'=>'enable','revision'=>2],'operator');
$db->exec('DELETE FROM analytics_permanent_reservations');
$gate=analyticsGate($event,$binding,$client,$managers,$policy,$now);
check($gate['allowed'],'genuine independent Ed25519 and reviewed receipt pass existing gate: '.json_encode($gate['reasons']));
foreach (['signature','identity','algorithm','manager','source'] as $failure) {
    $e=$event;$b=$binding;$c=$client;$p=$policy;
    if($failure==='signature')$e['upstream_manifest']['signature']=base64_encode(str_repeat('x',64));
    if($failure==='identity')$b['status']='REVIEW';
    if($failure==='algorithm')$p['approved_generators']=[];
    if($failure==='manager')$c['manager']='Unmapped owner';
    if($failure==='source')$e['run']['source_decision']['effective_as_of']='2000-01-01';
    check(!analyticsGate($e,$b,$c,$managers,$p,$now)['allowed'],$failure.' blocks without weakening gate');
}
$before=(int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn();
$preview=processAnalyticsEvent($db,$event,$client,$policy,'dry_run',$now);
check($preview['decision']==='DRY_RUN_READY','preflight real signed event');
check((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===$before,'preflight never creates task');
$created=processAnalyticsEvent($db,$event,$client,$policy,'enabled',$now);
check($created['decision']==='TASK_CREATED','signed event creates actual SQLite task');
$replay=processAnalyticsEvent($db,$event,$client,$policy,'enabled',$now);
check($replay['decision']==='TASK_EXISTS'&&$replay['task_id']===$created['task_id'],'lost ack replay returns same real task');

// TRUSTED_IMPORT keeps DECLARED, complete=false and a separately approved ID.
$trustedSource=$source;
$trustedSource['complete']=false;
$trustedSource['source_trust_mode']='TRUSTED_IMPORT';
$trustedSource['declared_complete_through']=$day;
$trustedSource['import_trust_approval_id']='synthetic-import-approval';
$trustedSource['source_revisions']=['sales_ftp'=>'7','clientsvr'=>$source['clients_revision']];
$trustedPolicy=$policy;
$trustedPolicy['source_trust_mode']='TRUSTED_IMPORT';
$trustedPolicy['import_trust_approval_id']='synthetic-import-approval';
$trustedPolicy['required_sources']=['sales_ftp','clientsvr'];
$resign=static function(array $e,array $s)use($upstream):array{
 $raw=analyticsEvidenceJson($s);
 $e['upstream_manifest']['payload_json']=$raw;
 $e['upstream_manifest']['signature']=base64_encode(sodium_crypto_sign_detached($raw,sodium_crypto_sign_secretkey($upstream)));
 $e['provenance']['proof_hash']=$e['run']['source_decision']['proof_hash']=hash('sha256',$raw);
 return $e;
};
$trusted=$resign($event,$trustedSource);
$trusted['run']['source_decision']['method']='OWNER_TRUSTED_IMPORT_SIGNED_V1';
$trusted['run']['source_decision']['coverage_status']='DECLARED';
$trusted['run']['source_decision']['business_freshness']='DECLARED_CURRENT';
check(analyticsGate($trusted,$binding,$client,$managers,$trustedPolicy,$now)['allowed'],'trusted import needs no independent completeness file');
check(analyticsSourceTrustContract($trustedPolicy,$trustedSource)['coverage_status']==='DECLARED','declaration is never confirmed');
foreach(['approval','complete','declaration','compatibility','sales_revision','clients_revision','stale'] as $failure){
 $s=$trustedSource;
 if($failure==='approval')$s['import_trust_approval_id']='unapproved';
 if($failure==='complete')$s['complete']=true;
 if($failure==='declaration')$s['declared_complete_through']='2000-01-01';
 if($failure==='compatibility')unset($s['complete_through']);
 if($failure==='sales_revision')$s['source_revisions']['sales_ftp']='different';
 if($failure==='clients_revision')$s['source_revisions']['clientsvr']='different';
 if($failure==='stale')$s['valid_until']=gmdate('c',$now-1);
 $e=$resign($trusted,$s);
 check(!analyticsGate($e,$binding,$client,$managers,$trustedPolicy,$now)['allowed'],'trusted '.$failure.' blocks');
}
// An independently prepared, still REVIEW preliminary binding is never promoted.
$preCard=['id'=>'preliminary-client','name'=>'Preliminary Customer','phones'=>[],
 'fields'=>['manager'=>'Signed Owner','status'=>'active','analytics_customer_kind'=>'CUSTOMER']];
$directory=new PDO('sqlite:'.clientsSqliteFile());
$directory->prepare('INSERT INTO clients VALUES(?,?)')->execute([$preCard['id'],json_encode($preCard,JSON_THROW_ON_ERROR)]);$directory=null;
$preCache=clientCatalogRevision();
$preName=['subject'=>['kind'=>'normalized_name','version'=>1,'key'=>'preliminary customer'],'run'=>['scope'=>$scope]];
$preGroup=analyticsReviewGroup($preName,[$preCard],$managers,$preCache);
$preHistory=array_replace($history,['client_key'=>'preliminary customer','history_hash'=>hash('sha256','preliminary-history')]);
$prepared=analyticsPreparePreliminary($db,$preName,$preGroup,$preHistory,true);
check($prepared['status']==='PRELIMINARY_MATCH','unambiguous preliminary prepared');
$preSubject=['kind'=>'external_id','version'=>1,'key'=>'preliminary-client'];
$preBinding=analyticsBinding($db,analyticsSubjectKey($preSubject,$scope));
$preReceipt=analyticsExportReview($preBinding,$preGroup,$preHistory,$now);
$preEvent=$trusted;$preEvent['signal_id']='preliminary-signal';$preEvent['subject']=$preSubject;
$preEvent['assignment']=['client_id'=>'preliminary-client','manager_id'=>3,'binding_revision'=>(int)$preBinding['revision']];
$preEvent['binding_receipt']=$preReceipt;$preEvent['matching']=$prepared;
unset($preEvent['matching']['persisted'],$preEvent['matching']['existing'],$preEvent['matching']['revision']);
$preSource=$trustedSource;$preSource['clients_revision']=$preSource['clients_cache_revision']=$preCache;
$preSource['source_revisions']['clientsvr']=$preCache;
$preSource['matching_policy']=ANALYTICS_PRELIMINARY_POLICY;
$preSource['assignments']=[['subject_hash'=>hash('sha256',json_encode('preliminary customer',JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
 'client_id'=>'preliminary-client','manager_id'=>3,'revision'=>(int)$preBinding['revision']]];
$preSource['review_groups']=[['client_id'=>'preliminary-client','group_hash'=>$preGroup['group_hash']]];
$preEvent=$resign($preEvent,$preSource);
$prePolicy=$trustedPolicy;$prePolicy['cache_revision']=$preCache;
$preClient=['id'=>'preliminary-client','manager'=>'Signed Owner','manager_conflict'=>false,'status'=>'active'];
check(analyticsGate($preEvent,$preBinding,$preClient,$managers,$prePolicy,$now)['allowed'],'signed preliminary delivery eligible');
$beforePre=(int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn();
check(processAnalyticsEvent($db,$preEvent,$preClient,$prePolicy,'dry_run',$now)['decision']==='DRY_RUN_READY','preliminary dry run');
check((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===$beforePre,'dry run does not alter tasks');
$preCreated=processAnalyticsEvent($db,$preEvent,$preClient,$prePolicy,'enabled',$now);
check($preCreated['decision']==='TASK_CREATED','signed preliminary creates task');
check(analyticsBinding($db,analyticsSubjectKey($preSubject,$scope))['status']==='REVIEW','preliminary remains REVIEW');
$changedBinding=$preBinding;$changedBinding['revision']=(int)$preBinding['revision']+1;
check(!analyticsGate($preEvent,$changedBinding,$preClient,$managers,$prePolicy,$now)['allowed'],'binding changed after receipt');
$conflictCard=$preCard;$conflictCard['id']='conflicting-card';$conflictCard['fields']['manager']='Alternate Owner';
saveManagerBinding($db,$admin,'Alternate Owner',6,null);
$conflict=analyticsReviewGroup($preName,[$preCard,$conflictCard],managerBindingRows($db),$preCache);
check(analyticsPreliminaryMatch($conflict)['status']==='BLOCKED','manager conflict never prepares');
$altered=$preEvent;$altered['matching']['manager_id']=6;
check(!analyticsGate($altered,$preBinding,$preClient,$managers,$prePolicy,$now)['allowed'],'unsigned matching alteration rejected');
// An existing task linked to this signal is preserved through replay.
$preAgain=processAnalyticsEvent($db,$preEvent,$preClient,$prePolicy,'enabled',$now);
check($preAgain['task_id']===$preCreated['task_id'],'preliminary replay same task');

// Ownership changes do not reassign previously created work.
saveManagerBinding($db,$admin,'Signed Owner',6,1);
$managers=managerBindingRows($db);$e=$event;$e['revision']=2;
$changed=processAnalyticsEvent($db,$e,$client,$policy,'enabled',$now);
check($changed['decision']==='REVIEW'&&in_array('MANAGER_CHANGED_REVIEW',$changed['reasons'],true),'changed owner reviewed');
$stmt=$db->prepare('SELECT assignee_id FROM manager_tasks WHERE id=?');$stmt->execute([$created['task_id']]);
check((int)$stmt->fetchColumn()===3,'existing task never reassigned');
check((int)$db->query('SELECT used_tasks FROM analytics_task_budget')->fetchColumn()===10,'pilot still exhausted');
echo "signed_delivery_test: real Ed25519, reviewed identity, gates, preflight, task commit, replay, owner change OK\n";
