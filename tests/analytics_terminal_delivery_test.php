<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
putenv('CALLTRACK_ANALYTICS_FEED=approved_churn');$checks=0;
foreach(['terminal','long-terminal','pending','unknown-410','wrong-run','network','string-terminal'] as $case){
 $db=analyticsTestDatabase();$transport=static function($method,$path,$body)use($case){
  if(str_contains($path,'/publications?'))return ['items'=>[['sequence'=>1,'run_id'=>'retired-fixture']],'next_after'=>null,'automation_allowed'=>true];
  if($case==='network')throw new RuntimeException('Synthetic network failure');
  $detail=['code'=>'RELEASE_TERMINATED','terminal'=>true,'run_id'=>'retired-fixture','reason'=>'SOURCE_SUPERSEDED_OR_EXPIRED'];
  if($case==='long-terminal')$detail['reason']=str_repeat('A',64);
  if($case==='wrong-run')$detail['run_id']='other-run';if($case==='unknown-410')$detail=[];if($case==='string-terminal')$detail['terminal']='true';
  throw new SalesJournalHttpException($case==='pending'?503:410,['detail'=>$detail]);
 };
 $thrown=false;try{$result=syncAnalytics($db,'enabled',[],$transport,1);}catch(RuntimeException $e){$thrown=true;}
 $terminal=in_array($case,['terminal','long-terminal'],true);
 if($thrown!==!$terminal)throw new RuntimeException('Wrong retirement classification '.$case);$checks++;
 $state=$db->query('SELECT * FROM analytics_automation_sync_state')->fetch(PDO::FETCH_ASSOC);
 if((int)$state['publication_sequence']!==($terminal?1:0)||($terminal?$state['current_run_id']!==null:$state['current_run_id']!=='retired-fixture'))throw new RuntimeException('Wrong checkpoint '.$case);$checks++;
 if($terminal&&strlen((string)$db->query('SELECT reason_code FROM analytics_delivery_rejections')->fetchColumn())>64)throw new RuntimeException('Terminal reason exceeds physical contract');
 if((int)$db->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()!==0)throw new RuntimeException('Error handling created tasks');$checks++;
}
$db=analyticsTestDatabase();$detail=['code'=>'RELEASE_TERMINATED','terminal'=>true,'run_id'=>'retired-fixture','reason'=>'POLICY_REVOKED'];
$transport=static fn($method,$path,$body)=>str_contains($path,'/publications?')?['items'=>[['sequence'=>1,'run_id'=>'retired-fixture']],'next_after'=>null,'automation_allowed'=>true]:throw new SalesJournalHttpException(410,['detail'=>$detail]);
syncAnalytics($db,'dry_run',[],$transport,1);
if((int)$db->query('SELECT publication_sequence FROM analytics_automation_sync_state')->fetchColumn()!==0)throw new RuntimeException('Dry retirement touched enabled checkpoint');$checks++;
echo 'PASS terminal delivery: '.$checks." assertions; transient/unknown errors retained, authenticated terminal release retired, no tasks\n";
