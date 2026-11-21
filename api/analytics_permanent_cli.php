<?php
declare(strict_types=1);
/** Local transport only. No HTTP task creation, credentials or signatures in output. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    require_once __DIR__.'/analytics_sync.php';
    require_once __DIR__.'/analytics_permanent.php';
    require_once __DIR__.'/analytics_verified_bindings.php';
    $command=$argv[1]??'';
    if(!in_array($command,['health','preflight','deliver','policy'],true)||count($argv)>($command==='policy'?3:2))throw new DomainException('INVALID_COMMAND');
    $raw=stream_get_contents(STDIN,4194305);
    if($raw===false||strlen($raw)>4194304)throw new DomainException('INPUT_TOO_LARGE');
    $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new DomainException('INVALID_INPUT');
    $pdo=getPdo();
    if($command==='health'){
        foreach(['analytics_inbox','analytics_client_bindings','manager_tasks','analytics_permanent_policy','analytics_permanent_reservations','analytics_task_policy'] as $table){
            $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');
        }
        if(!extension_loaded('sodium'))throw new DomainException('SIGNATURE_SUPPORT_REQUIRED');
        echo '{"receiver":"reachable"}'.PHP_EOL;exit;
    }
    if($command==='policy'){
        if(!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new DomainException('OPERATOR_CONTEXT_REQUIRED');
        echo json_encode(saveAnalyticsPermanentPolicy($pdo,$input,$argv[2]??''),JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }
    $mode=analyticsIntegrationMode();
    if($mode==='disabled'||($command==='deliver'&&$mode!=='enabled'))throw new DomainException('RECEIVER_DISABLED');
    echo json_encode(analyticsPermanentPage($pdo,$input,$command,time()),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    // Missing dependencies, SQL, customer data and token-bearing exceptions stay private.
    fwrite(STDERR,"Permanent churn command failed; no success acknowledged\n");exit(1);
}
