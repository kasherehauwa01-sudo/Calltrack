<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_integration.php';
try{
 $pdo=getPdo();$actor=requireWebUser($pdo);if(($actor['role']??'')!=='admin')throw new DomainException('Admin only');
 if($_SERVER['REQUEST_METHOD']==='GET')sendJson(['status'=>'success','budget'=>analyticsBudget($pdo)]);
 if($_SERVER['REQUEST_METHOD']==='POST'){saveAnalyticsBudget($pdo,$actor,readJsonBody());sendJson(['status'=>'success']);}
 sendJson(['status'=>'error'],405);
}catch(TaskConflict $e){sendJson(['status'=>'error'],409);}
catch(DomainException $e){sendJson(['status'=>'error'],403);}
catch(InvalidArgumentException $e){sendJson(['status'=>'error'],422);}
catch(Throwable $e){error_log('analytics budget: '.get_class($e));sendJson(['status'=>'error'],503);}
