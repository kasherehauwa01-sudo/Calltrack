<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1')throw new RuntimeException('Synthetic tests only');
require_once __DIR__.'/../api/analytics_client_group_review.php';
require_once __DIR__.'/../api/analytics_candidate_report.php';
require_once __DIR__.'/../api/manager_bindings_service.php';
$managers=[['id'=>3,'display_name'=>'Owner Three','clients_manager_name'=>'Owner Three','status'=>'VERIFIED','is_active'=>1],['id'=>6,'display_name'=>'Owner Six','clients_manager_name'=>'Owner Six','status'=>'VERIFIED','is_active'=>1]];
// Use the existing owner index contract rather than granting any verified client identity.
$rows=[['id'=>'fixture-card','name'=>' Fixture Buyer ','manager'=>'Owner Three','phones'=>['79990000001'],'manager_conflict'=>false]];
$missing=analyticsClientGroupReview($rows,$managers,'Fixture Buyer',[]);
if($missing['status']!=='REVIEW')throw new RuntimeException('Missing phone became identity proof');
$group=analyticsClientGroupReview($rows,$managers,'fixture buyer',['79990000001']);
if($group['status']!=='CANDIDATE'||$group['manager_id']!==3||$group['client_ids']!==['fixture-card']||$group['requires_manual_verification']!==true)throw new RuntimeException('Advisory granted identity authority');
$rows[]=['id'=>'technical','name'=>'Fixture Buyer','manager'=>'','phones'=>['79990000001']];
if(analyticsClientGroupReview($rows,$managers,'fixture buyer',['79990000001'])['status']==='VERIFIED')throw new RuntimeException('Technical card authorized');
$rows[]=['id'=>'conflicting','name'=>'fixture buyer','manager'=>'Owner Six','phones'=>['79990000001'],'manager_conflict'=>true];
if(analyticsClientGroupReview($rows,$managers,'fixture buyer',['79990000001'])['reason']!=='MANAGER_CONFLICT')throw new RuntimeException('Conflict lost');
if(analyticsCandidateReportLookup(['signal_type'=>'CHURN_RISK','subject'=>['kind'=>'external_id','key'=>'fixture-card']])!==null)throw new RuntimeException('Report must not authorize external identity');
$admin=file_get_contents(__DIR__.'/../api/analytics_admin.php');
if(!str_contains($admin,'requireWebAdmin')||!str_contains($admin,'analyticsCandidateReportLookup')||!str_contains($admin,'Используйте проверку всей группы карточек'))throw new RuntimeException('Admin workflow integration lost');
$js=file_get_contents(__DIR__.'/../analizmop/manager-tasks.js');
foreach(['candidateView(row)','analyticsReviewStyle','data-candidate-id','data-group-review',"binding_status').value = 'REVIEW'"] as $part)if(!str_contains($js,$part))throw new RuntimeException('UI behavior lost '.$part);
echo "PASS server advisory preserved; candidates never authorize, conflict guard and review UI retained\n";
