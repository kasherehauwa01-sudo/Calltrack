<?php
declare(strict_types=1);
$root=$argv[1] ?? dirname(__DIR__);
require $root.'/api/manager_tasks_view.php';
require $root.'/api/manager_tasks_service.php';
function taskViewClients(array $ids): array {return array_fill_keys($ids,['name'=>'ООО Радуга']);}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,string $class):void{try{$fn();}catch(Throwable $e){check($e instanceof $class,'Wrong exception: '.get_class($e));return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE web_users(id INTEGER PRIMARY KEY, display_name TEXT, role TEXT,is_active INTEGER); INSERT INTO web_users VALUES(1,"Первый","manager",1),(2,"Второй","manager",1)');
$p->exec('CREATE TABLE manager_tasks(id TEXT PRIMARY KEY,client_id TEXT,title TEXT,assignee_id INTEGER,status TEXT,priority TEXT,due_at TEXT,version INTEGER DEFAULT 1,evidence_json TEXT,family TEXT,result TEXT,description TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE manager_task_events(task_id TEXT,actor_id INTEGER,event_type TEXT,data_json TEXT)');
$p->exec('CREATE TABLE analytics_open_task_slots(slot_key TEXT,task_id TEXT)');
$e=json_encode(['signal'=>['signal_type'=>'CHURN_RISK','last_purchase'=>'2026-08-27','metrics'=>['median_interval'=>20,'days_without_purchase'=>60]]]);
$q=$p->prepare('INSERT INTO manager_tasks(id,client_id,title,assignee_id,status,priority,evidence_json,family,result,description) VALUES(?,?,?,?,?,?,?,?,?,?)');
for($i=1;$i<=55;$i++)$q->execute([sprintf('%03d',$i),'c1','Риск ухода клиента',1,'OPEN','NORMAL',$e,'INACTIVITY','','Old']);
$q->execute(['060','c2','Done',1,'DONE','NORMAL',$e,'INACTIVITY','Done','Old']);
$q->execute(['061','c2','Other',2,'OPEN','NORMAL',json_encode(['signal'=>['signal_type'=>'FUTURE_TYPE']]),'MANUAL','','']);
$q->execute(['062','c2','Cancelled',1,'CANCELLED','NORMAL',null,'MANUAL','No','']);
$m=['id'=>1,'role'=>'manager'];$admin=['id'=>3,'role'=>'admin'];$boss=['id'=>4,'role'=>'supervisor'];
$r=taskViewList($p,$m,[]);check(count($r['items'])===50&&$r['next_after']==='050','Pagination first');check($r['unfinished_count']===55,'Manager count');check($r['items'][0]['client_name']==='ООО Радуга','Client name');check($r['assignees']===[],'No manager selector');check(!in_array('FUTURE_TYPE',array_column($r['types'],'key')),'Types scoped');
$r=taskViewList($p,$m,['after'=>'050']);check(count($r['items'])===6&&$r['next_after']===null,'Pagination tail');
$r=taskViewList($p,$m,['completed'=>'1']);check(array_column($r['items'],'id')===['060'],'Only done');check($r['unfinished_count']===55,'Count independent');
$r=taskViewList($p,$m,['manager'=>'2','type'=>'FUTURE_TYPE']);check($r['items']===[],'Manager cannot escape scope');
$r=taskViewList($p,$boss,['manager'=>'2']);check(count($r['items'])===1&&count($r['assignees'])===2&&$r['unfinished_count']===56,'Supervisor filtering');
check(taskViewDescription(['evidence_json'=>$e])==='Частота покупок: каждые 20 дней. Последняя покупка: 2026-08-27. Без покупок: 60 дн. Связаться с клиентом, выяснить причину отсутствия заказов и зафиксировать результат.','Description');
updateManagerTask($p,$m,'060',['version'=>1,'status'=>'DONE','result'=>'Исправлено']);
check($p->query("SELECT result FROM manager_tasks WHERE id='060'")->fetchColumn()==='Исправлено','Done editable');
rejects(fn()=>updateManagerTask($p,$m,'060',['version'=>1,'result'=>'stale']),TaskConflict::class);
rejects(fn()=>updateManagerTask($p,$m,'060',['version'=>2,'status'=>'OPEN']),TaskConflict::class);
rejects(fn()=>updateManagerTask($p,$m,'061',['version'=>1,'result'=>'other']),DomainException::class);
rejects(fn()=>updateManagerTask($p,$boss,'001',['version'=>1,'result'=>'other']),DomainException::class);
rejects(fn()=>updateManagerTask($p,$m,'001',['version'=>1,'priority'=>'HIGH']),DomainException::class);
rejects(fn()=>updateManagerTask($p,$m,'001',['version'=>1,'status'=>'DONE','result'=>'']),InvalidArgumentException::class);
$p->exec("INSERT INTO analytics_open_task_slots VALUES('slot','001')");updateManagerTask($p,$m,'001',['version'=>1,'status'=>'DONE','result'=>'Связались']);check($p->query('SELECT COUNT(*) FROM analytics_open_task_slots')->fetchColumn()==0,'Slot released');
echo "PASS: scoped counts, filters, pagination, dynamic types, description, completed editing, conflicts, permissions, slots\n";

$q->execute(['090','c2','Legacy',1,'OPEN','NORMAL','{broken','MANUAL','','']);
$r=taskViewList($p,$m,['type'=>'MANUAL','status'=>'OPEN']);
check(array_column($r['items'],'id')===['090'],'Malformed evidence uses family without SQL error');
check(!array_key_exists('evidence_json',$r['items'][0]),'List never transfers proof bundles');
echo "PASS: malformed legacy JSON and bounded list payload\n";
