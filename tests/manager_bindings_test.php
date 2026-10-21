<?php
declare(strict_types=1);
require_once __DIR__.'/../api/manager_bindings_service.php';
require_once __DIR__.'/../api/client_catalog.php';
function checkBinding(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
checkBinding(managerNameKey('Тестов Тест Тестович')===managerNameKey('Тест Тестович Тестов'),'Cyrillic word-order example');
checkBinding(managerNameKey('Example First Middle')===managerNameKey('Middle Example First'),'word order suggestion');
checkBinding(clientManagerField(['manager'=>'Canonical'])['label']==='Canonical','English serializer');
checkBinding(clientManagerField(['Менеджер'=>'Legacy'])['label']==='Legacy','legacy serializer');
checkBinding(clientManagerField(['manager'=>'','Менеджер'=>'Legacy'])['label']==='Legacy','empty fallback');
checkBinding(clientManagerField(['manager'=>'One','Менеджер'=>'Two'])['conflict'],'conflict quarantined');
checkBinding(clientManagerField(['manager'=>['bad']])['conflict'],'invalid type quarantined');
$records=clientCatalogRecords([['id'=>'a','name'=>'Buyer','phones'=>['9000000000'],'fields'=>['manager'=>'Owner']]]);
checkBinding($records['a']['manager']==='Owner','full catalog English path');
$owner=['designation_key'=>clientManagerDesignationKey('Old Label'),'web_user_id'=>3,'role'=>'manager','is_active'=>1,'revision'=>2];
checkBinding(resolveBoundManager('Old Label',[$owner])['user_id']===3,'explicit owner');
checkBinding(resolveBoundManager('Old Label',[$owner+[]])['status']==='VERIFIED','verified status');
checkBinding(resolveBoundManager('',[])['status']==='MISSING_MANAGER','missing label');
checkBinding(resolveBoundManager('Different',[$owner])['status']==='UNMAPPED_MANAGER','unmapped label');
$inactive=$owner;$inactive['is_active']=0;
checkBinding(resolveBoundManager('Old Label',[$inactive])['user_id']===null,'inactive blocks');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE web_users(id INTEGER PRIMARY KEY,display_name TEXT,role TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO web_users VALUES(1,'Admin','admin',1),(3,'Manager','manager',1),(4,'Inactive','manager',0),(5,'Other','manager',1)");
$pdo->exec('CREATE TABLE client_manager_bindings(designation_key TEXT PRIMARY KEY,designation TEXT,web_user_id INTEGER,revision INTEGER DEFAULT 1,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE client_manager_binding_events(id INTEGER PRIMARY KEY,designation_key TEXT,old_user_id INTEGER,new_user_id INTEGER,actor_id INTEGER)');
$admin=['id'=>1,'role'=>'admin'];
saveManagerBinding($pdo,$admin,'Alias One',3,null);saveManagerBinding($pdo,$admin,'Alias Two',3,null);
checkBinding(count(managerBindingRows($pdo))===2,'several aliases one user');
try{saveManagerBinding($pdo,$admin,'Alias One',5,null);throw new RuntimeException('conflict accepted');}catch(ManagerBindingConflict $e){}
try{saveManagerBinding($pdo,['id'=>3,'role'=>'manager'],'Forbidden',3,null);throw new RuntimeException('ACL failure');}catch(DomainException $e){}
try{saveManagerBinding($pdo,$admin,'Inactive',4,null);throw new RuntimeException('inactive accepted');}catch(InvalidArgumentException $e){}
saveManagerBinding($pdo,$admin,'Alias One',5,1);
checkBinding(resolveBoundManager('Alias One',managerBindingRows($pdo))['user_id']===5,'explicit transfer');
try{saveManagerBinding($pdo,$admin,'Alias One',3,1);throw new RuntimeException('stale revision accepted');}catch(ManagerBindingConflict $e){}
saveManagerBinding($pdo,$admin,'Alias One',null,2);
checkBinding(count(managerBindingRows($pdo))===1,'remove alias');
checkBinding((int)$pdo->query('SELECT COUNT(*) FROM client_manager_binding_events')->fetchColumn()===4,'audit committed changes only');
saveManagerBinding($pdo,$admin,'Alias One',3,null);
try{saveManagerBinding($pdo,$admin,'Alias One',5,1);throw new RuntimeException('ABA stale revision accepted');}catch(ManagerBindingConflict $e){}
$directory=managerDesignationDirectory([['fields'=>['manager'=>'One']],['fields'=>['Менеджер'=>'One']],['fields'=>['manager'=>'One','Менеджер'=>'Two']]]);
checkBinding(count($directory)===1&&$directory[0]['cached_card_count']===2,'directory mixed schemas/conflict');
$explicitManagers=[['id'=>3,'display_name'=>'Other Name','clientsvr_bindings'=>[['designation_key'=>clientManagerDesignationKey('Legacy Label'),'web_user_id'=>3,'role'=>'manager','is_active'=>1,'display_name'=>'Other Name']]]];
checkBinding((int)clientCatalogOwner(['manager'=>'Legacy Label'],$explicitManagers)['id']===3,'explicit alias overrides name resolver');
$explicitManagers[0]['clientsvr_bindings'][0]['is_active']=0;
checkBinding(clientCatalogOwner(['manager'=>'Legacy Label'],$explicitManagers)===null,'inactive explicit binding cannot fall back to name matching');
echo "manager_bindings_test: OK\n";
