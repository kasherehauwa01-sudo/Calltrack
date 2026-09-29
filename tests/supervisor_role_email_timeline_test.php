<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/api/web_auth.php';

function assertTrue(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }

assertTrue(webManagerScope(['role'=>'admin','id'=>1,'display_name'=>'Admin'])===null,'Admin должен видеть всех менеджеров');
assertTrue(webManagerScope(['role'=>'supervisor','id'=>2,'display_name'=>'Руководитель'])===null,'Supervisor должен видеть всех менеджеров');
$managerScope=webManagerScope(['role'=>'manager','id'=>3,'display_name'=>'Селянкина Татьяна']);
assertTrue($managerScope===['user_phone'=>'web-user-3','manager'=>'Селянкина Татьяна'],'Manager scope изменился');
assertTrue(managerNameKey(' Селянкина   Татьяна ')===managerNameKey('Татьяна Селянкина'),'Порядок ФИО должен канонизироваться');

$auth=file_get_contents($root.'/api/web_auth.php');
assertTrue(str_contains($auth,"\$user['role'] !== 'admin'"),'Admin API должен проверять строго role=admin');
$users=file_get_contents($root.'/api/web_users.php');
assertTrue(str_contains($users,"['admin','supervisor','manager']"),'CRUD должен принимать supervisor');
$frontend=file_get_contents($root.'/analizmop/index.html');
assertTrue(str_contains($frontend,'<option value="supervisor">Руководитель</option>'),'В форме нет роли Руководитель');
assertTrue(str_contains($frontend,"if(user.role!=='admin')"),'Admin panel должен быть виден только admin');
assertTrue(str_contains($frontend,'group.emails')&&str_contains($frontend,'sortClientTimelineEvents(events)'),'Email не включены в общую timeline');

echo "supervisor role and email timeline: OK\n";
