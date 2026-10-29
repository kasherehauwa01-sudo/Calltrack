<?php
declare(strict_types=1);
require __DIR__.'/../api/client_catalog.php';
function verify($ok,$label){if(!$ok)throw new RuntimeException($label);}
$target=['id'=>'target','name'=>'Радуга','phones'=>['89990000001'],'fields'=>['email'=>'a@example.test','Менеджер'=>'Иван Иванов']];
$rows=static function()use($target){yield $target;for($i=0;$i<30000;$i++)yield ['id'=>'other-'.$i,'name'=>'Другой '.$i,'phones'=>[],'fields'=>['large'=>str_repeat('x',12000)]];};
$before=memory_get_usage(true);$r=clientCatalogLookup('target',$rows);
verify($r['record']['name']==='Радуга'&&$r['name_unique'],'Target resolved');
verify(isset($r['phone_index']['9990000001']),'Unique phone retained');
verify(memory_get_peak_usage(true)-$before<32*1024*1024,'Bounded memory for large catalogue');
$shared=$target;$shared['id']='other';
$r=clientCatalogLookup('target',static fn()=>[$target,$shared]);
verify(!$r['name_unique']&&$r['phone_index']===[]&&$r['email_index']===[],'Shared identity not attributed');
try{clientCatalogLookup('target',static fn()=>[$target,$target]);throw new RuntimeException('Duplicate allowed');}catch(ClientCatalogAccessDenied $e){}
verify(!clientCatalogVisible(['id'=>2,'role'=>'manager','display_name'=>'Другой'],$r['record'],[['id'=>1,'display_name'=>'Иван Иванов']]),'Other manager denied');
echo "PASS: 30000 large clients streamed, bounded memory, shared addresses/names, duplicate IDs, manager scope\n";
