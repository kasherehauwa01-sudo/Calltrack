<?php
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='calltrack_max_history_test')throw new RuntimeException('Disposable fixture DB required');
require_once __DIR__.'/../../api/client_catalog.php';
$pdo=getPdo();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='calltrack_max_history_test')throw new RuntimeException('Disposable fixture DB required');
$existing=[];foreach(clientsCacheRowsForEmailLookup() as $row)if(!str_starts_with((string)($row['id']??''),'catalog-')){$row['fields']['Менеджер']='One';$existing[]=$row;}
$raw=[['id'=>'catalog-a','Наименование'=>'Альфа Каталог','Телефоны'=>'+7 (900) 000-00-01','email'=>'catalog-a@example.test','Менеджер'=>'One'],
 ['id'=>'catalog-b','Наименование'=>'Бета Каталог','Телефоны'=>'89000000002','email'=>'catalog-b@example.test','Менеджер'=>'Two'],
 ['id'=>'catalog-none','Наименование'=>'Без звонков','Телефоны'=>'9000000003','Менеджер'=>'One'],
 ['id'=>'catalog-old','Наименование'=>'Старый клиент','Телефоны'=>'9000000004','Менеджер'=>'One']];
for($i=0;$i<105;$i++)$raw[]=['id'=>'catalog-extra-'.$i,'Наименование'=>sprintf('Клиент каталога %03d',$i),'Телефоны'=>(string)(9000100000+$i),'Менеджер'=>$i<60?'One':'Two'];
writeClientsCache([...$existing,...normalizeClientsPayload($raw)]);
$stmt=$pdo->prepare("INSERT IGNORE INTO calls(id_db,call_date,call_time,phone,call_type,duration,manager,user_phone,client,comment,call_id) VALUES(?,?,'10:00:00',?,'Исходящий','60',?,?,'','Catalog note',?)");
foreach([[2001,'2026-10-19','+79000000001','One','web-user-1'],[2002,'2026-10-13','89000000002','Two','web-user-2'],[2004,'2026-09-01','9000000004','One','web-user-1']] as [$id,$date,$phone,$manager,$owner])$stmt->execute([$id,$date,$phone,$manager,$owner,'catalog-call-'.$id]);
$pdo->exec("INSERT IGNORE INTO email_messages(id,mailbox_id,manager_name,direction,sent_at,client_email,to_emails,subject,imap_uid) VALUES(2001,1,'One','outgoing','2026-10-18 12:30:00','catalog-a@example.test','catalog-a@example.test','Catalog outgoing email',2001),(2002,1,'Two','outgoing','2026-10-13 12:30:00','catalog-b@example.test','catalog-b@example.test','Other owner email',2002)");
$pdo->exec("INSERT IGNORE INTO max_messages(source_event_id,direction,phone,contact_name,occurred_at,user_phone,manager) VALUES('catalog-max-a','outgoing','9000000001','Альфа Каталог','2026-10-18 17:30:00','web-user-1','One')");
echo "Catalog fixture ready: assignments, both managers, no-call clients, pagination, calls/email/MAX\n";
