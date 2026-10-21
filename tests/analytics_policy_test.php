<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_task_policy.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function policyCheck(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];
$initial=analyticsTaskPolicy($pdo);$gate=analyticsTaskPolicyGateConfig($initial);
policyCheck(!$gate['policy_approved']&&$gate['min_confidence']===null&&$gate['enabled_types']===[],'no arbitrary defaults/approval');
foreach($initial['types'] as $type)policyCheck($type['priority']===null&&$type['due_days']===null&&(int)$type['enabled']===0,'empty type policy');
try{saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'approve','confirm_business_approval'=>true]);throw new RuntimeException('Incomplete policy approved');}catch(InvalidArgumentException $e){}
$draft=['revision'=>1,'action'=>'save_draft','min_confidence'=>0.65,'types'=>[['signal_type'=>'PURCHASE_OVERDUE','enabled'=>true,'priority'=>'NORMAL','due_days'=>3],['signal_type'=>'CHURN_RISK','enabled'=>false,'priority'=>'HIGH','due_days'=>1]]];
saveAnalyticsTaskPolicy($pdo,$admin,$draft);
$after=analyticsTaskPolicy($pdo);policyCheck((int)$after['revision']===2&&!(int)$after['approved'],'saving draft never approves');
try{saveAnalyticsTaskPolicy($pdo,$admin,$draft);throw new RuntimeException('stale draft accepted');}catch(TaskConflict $e){}
try{saveAnalyticsTaskPolicy($pdo,['id'=>3,'role'=>'manager'],['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);throw new RuntimeException('Manager can approve');}catch(DomainException $e){}
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
$approved=analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo));policyCheck($approved['policy_approved']&&$approved['enabled_types']===['PURCHASE_OVERDUE']&&$approved['due_days_by_type']['PURCHASE_OVERDUE']===3,'explicit per-type approval');
$draft['revision']=3;saveAnalyticsTaskPolicy($pdo,$admin,$draft);policyCheck(!analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo))['policy_approved'],'edits revoke approval');
policyCheck((int)$pdo->query('SELECT COUNT(*) FROM analytics_task_policy_events')->fetchColumn()===3,'policy audit');
echo "analytics_policy_test: unset defaults + per-type policy + explicit approval + revoke + ACL/CAS OK\n";
