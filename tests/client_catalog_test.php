<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_catalog.php';
function catalogCheck(bool $value,string $label): void {if(!$value)throw new RuntimeException($label);}
$managers=[['id'=>1,'display_name'=>'Марина Сергеевна Пашута'],['id'=>2,'display_name'=>'Татьяна Селянкина']];
$user=['id'=>1,'display_name'=>'Марина Сергеевна Пашута','role'=>'manager'];$admin=['id'=>3,'display_name'=>'Admin','role'=>'admin'];$boss=$admin;$boss['role']='supervisor';
$rows=[];foreach([['a','Альфа','+7 (989) 282-98-42','Пашута Марина Сергеевна'],['b','Бета','89990000002','Селянкина Татьяна'],['c','Гамма','9990000003','Пашута Марина Сергеевна'],['d','Дельта','9990000004','Пашута Марина Сергеевна']] as [$id,$name,$phone,$manager])$rows[]=['id'=>$id,'name'=>$name,'phones'=>[$phone],'fields'=>['Менеджер'=>$manager,'email'=>$id.'@example.test']];
$records=clientCatalogRecords($rows);$now=new DateTimeImmutable('2026-10-19 12:00:00',new DateTimeZone('Europe/Volgograd'));
$activity=['a'=>'2026-10-19 10:00:00','b'=>'2026-10-13 10:00:00','c'=>'2026-10-01 10:00:00'];
$page=static fn($who,$filters=[])=>clientCatalogPage($records,$managers,$activity,$who,$filters,$now);
catalogCheck($page($user)['total']===3,'manager only assigned clients, including client without activity');
catalogCheck($page($admin)['total']===4&&$page($boss)['total']===4,'admin and supervisor all clients');
catalogCheck($page($user,['search'=>'аЛЬФ'])['items'][0]['id']==='a','case-insensitive name search');
catalogCheck($page($user,['search'=>'Неизвестный1'])['total']===0,'digits inside a name are not a phone query');
foreach(['9892829842','+7 (989) 282-98-42','89892829842','28298'] as $phone)catalogCheck($page($user,['search'=>$phone])['items'][0]['id']==='a','formatted and partial phone search');
catalogCheck($page($admin,['manager'=>'2'])['items'][0]['id']==='b','admin manager filter');
try{$page($user,['manager'=>'2']);throw new RuntimeException('foreign filter allowed');}catch(ClientCatalogAccessDenied $e){}
catalogCheck($page($user,['user_phone'=>'web-user-2'])['total']===3,'browser owner ignored');
foreach(['today'=>1,'7'=>2,'30'=>3,'inactive'=>1] as $filter=>$count)catalogCheck($page($admin,['activity'=>$filter])['total']===$count,$filter.' activity');
catalogCheck(array_column($page($admin,['sort'=>'activity_desc'])['items'],'id')===['a','b','c','d'],'activity newest, null last');
catalogCheck(array_column($page($admin,['sort'=>'activity_asc'])['items'],'id')===['c','b','a','d'],'activity oldest, null last');
catalogCheck(array_column($page($admin,['sort'=>'name_asc'])['items'],'id')===['a','b','c','d'],'name ascending');
catalogCheck(array_column($page($admin,['sort'=>'name_desc'])['items'],'id')===['d','c','b','a'],'name descending');
$p=$page($admin,['sort'=>'name_asc','page_size'=>2,'page'=>2]);catalogCheck($p['total']===4&&count($p['items'])===2&&$p['items'][0]['id']==='c','pagination');
catalogCheck($page($admin,['page'=>999])['page']===1,'out of bounds page clamped');
catalogCheck(!clientCatalogVisible($user,$records['b'],$managers),'foreign client ID denied');
catalogCheck(!clientCatalogVisible($user,$records['a'],[...$managers,['id'=>4,'display_name'=>'Пашута Марина Сергеевна']]),'ambiguous manager assignment fails closed');
$shared=$records;$shared['b']['phones']=$shared['a']['phones'];catalogCheck(!isset(clientCatalogAddressIndex($shared,'phones')['9892829842']),'shared phone not arbitrarily assigned');
catalogCheck(!isset(clientCatalogRecords([$rows[0],$rows[0]])['a']),'duplicate ID fails closed');
foreach($page($admin)['items'] as $item)catalogCheck(array_keys($item)===['id','name','phones','manager','last_activity_at'],'response field allowlist');
echo "client_catalog_test: role filtering, search, filters, sorting, pagination, no-call client, ambiguity OK\n";
