<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog_purchases.php';
if(PHP_SAPI!=='cli')sendJson(['status'=>'error','message'=>'Обновление доступно только из CLI'],403);
try{runClientCatalogPurchasesRefresh();fwrite(STDOUT,"Client purchase dates refreshed\n");}
catch(Throwable $error){fwrite(STDERR,"Client purchase dates refresh failed\n");exit(1);}
