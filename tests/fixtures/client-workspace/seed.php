<?php
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1')exit("Test fixtures require CALLTRACK_TEST_FIXTURES=1\n");
require __DIR__.'/../../../api/config.php';require __DIR__.'/../../../api/android_auth.php';require __DIR__.'/../../../api/client_directory.php';require __DIR__.'/../../../api/email_sync.php';
$p=getPdo();ensureWebAuthTables($p);ensureAndroidAuthTables($p);ensureEmailTables($p);
foreach([[1,'One','manager'],[2,'Two','manager'],[3,'Admin','admin'],[4,'Boss','supervisor']] as [$id,$name,$role])$p->prepare('INSERT IGNORE INTO web_users(id,display_name,login,pin_hash,role) VALUES(?,?,?,?,?)')->execute([$id,$name,strtolower($name).'@example.test',password_hash('12345678',PASSWORD_DEFAULT),$role]);
foreach([[1,'+7 (999) 111-22-33','web-user-1','One'],[2,'89991112233','web-user-1','One'],[3,'+79991112233','web-user-2','Two'],[4,'+79992223344','web-user-1','One'],[5,'+79993334455','web-user-2','Two']] as [$id,$phone,$owner,$name])$p->prepare("INSERT IGNORE INTO calls(id_db,call_date,call_time,phone,call_type,duration,manager,user_phone,client,comment,call_id) VALUES(?,'2026-10-15','10:00:00',?,'Исходящий','60',?,?,'','Test note',?)")->execute([$id,$phone,$name,$owner,'fixture-'.$id]);
$p->prepare("INSERT IGNORE INTO web_user_tokens(web_user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([1,hash('sha256',str_repeat('a',43))]);
writeClientsCache(normalizeClientsPayload([['id'=>'client-1','Наименование'=>'Покупатель','Телефоны'=>'+79992223344, +79992223355','email'=>'buyer@example.test']]));
$p->exec("INSERT IGNORE INTO personal_contacts(user_phone,manager,contact_phone,personal_flag,updated_at) VALUES('web-user-1','One','+7 999 1112233',0,NOW())");
$p->exec("INSERT IGNORE INTO email_messages(id,mailbox_id,manager_name,direction,sent_at,client_email,imap_uid) VALUES(10,1,'One','outgoing','2026-10-15 10:00:00','buyer@example.test',10),(11,1,'Two','outgoing','2026-10-15 10:00:00','buyer@example.test',11)");
echo "fixture ready\n";
