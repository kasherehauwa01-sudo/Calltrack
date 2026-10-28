<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='analytics_sync_test'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-login'){
 require_once __DIR__.'/../../../api/config.php';require_once __DIR__.'/../../../api/web_auth.php';
 startWebSession();$_SESSION['web_user_id']=(int)($_GET['id']??0);$_SESSION['created_at']=time();$_SESSION['last_seen_at']=time();
 sendJson(['status'=>'success','csrf'=>webCsrfToken()]);
}
$routes=['/vr/calltrack/api/analytics_verified_review.php'=>'analytics_verified_review.php','/vr/calltrack/api/analytics_verified_export.php'=>'analytics_verified_export.php'];
if(!isset($routes[$path])){http_response_code(404);exit;}
require __DIR__.'/../../../api/'.$routes[$path];
