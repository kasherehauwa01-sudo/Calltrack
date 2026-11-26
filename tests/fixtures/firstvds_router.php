<?php
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='calltrack_max_history_test'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$prefix='/vr/calltrack/api/';
if(!str_starts_with($path,$prefix)){http_response_code(404);exit;}
$root=realpath(__DIR__.'/../../api');
$file=realpath($root.'/'.substr($path,strlen($prefix)));
if(!$file||!str_starts_with($file,$root.'/')||!str_ends_with($file,'.php')){http_response_code(404);exit;}
require $file;
