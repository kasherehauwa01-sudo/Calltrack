<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='analytics_http_test'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-login'){
    require_once __DIR__.'/../../../api/web_auth.php';require_once __DIR__.'/../../../api/config.php';
    startWebSession();$_SESSION['web_user_id']=(int)($_GET['id']??0);$_SESSION['created_at']=time();$_SESSION['last_seen_at']=time();
    sendJson(['status'=>'success','csrf'=>webCsrfToken()]);
}
if($path==='/fixture-ui'){
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="tasksView"></div><form id="webUserForm"></form><script>window.calltrackApi={csrfToken:"",requestJson:async function(url,options={}){const headers=new Headers(options.headers||{});if(options.method&&options.method!=="GET")headers.set("X-CSRF-Token",this.csrfToken);const response=await fetch(url,{credentials:"same-origin",...options,headers});const data=await response.json();if(!response.ok)throw Error(data.message||"API error");return data;}};</script><script src="/vr/calltrack/analizmop/manager-tasks.js"></script><script src="/vr/calltrack/analizmop/manager-bindings.js"></script><script src="/vr/calltrack/analizmop/analytics-policy.js"></script></body></html>';return;
}
$prefix='/vr/calltrack/';
if(!str_starts_with($path,$prefix)){http_response_code(404);return;}
$file=realpath(__DIR__.'/../../../'.substr($path,strlen($prefix)));$root=realpath(__DIR__.'/../../../');
if(!$file||!str_starts_with($file,$root.'/')||!is_file($file)){http_response_code(404);return;}
if(str_ends_with($file,'.php'))require $file;
elseif(str_ends_with($file,'.js')){header('Content-Type: text/javascript');readfile($file);}
else{http_response_code(404);}
