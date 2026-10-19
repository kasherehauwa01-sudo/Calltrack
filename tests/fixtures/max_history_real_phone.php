<?php
// Synthetic copy of the user-provided example, exclusively in the disposable fixture DB.
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='calltrack_max_history_test')throw new RuntimeException('Isolated fixture required');
require_once __DIR__.'/../../api/config.php';
require_once __DIR__.'/../../api/client_directory.php';
$pdo=getPdo();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='calltrack_max_history_test')throw new RuntimeException('Isolated DB required');
writeClientsCache(normalizeClientsPayload([
 ['id'=>'client-1','Наименование'=>'Покупатель','Телефоны'=>'+79992223344, +79992223355','email'=>'buyer@example.test'],
 ['id'=>'client-real-phone','Наименование'=>'Инесса Кораблева','Телефоны'=>'+7 (989) 282-98-42','email'=>'inessa@example.test'],
]));
$pdo->exec("INSERT IGNORE INTO calls(id_db,call_date,call_time,phone,call_type,duration,manager,user_phone,client,comment,call_id) VALUES(90,'2026-10-19','00:20:00','+7 (989) 282-98-42','Исходящий','60','One','web-user-1','','Real phone fixture','fixture-real-phone')");
$insert=$pdo->prepare('INSERT IGNORE INTO max_messages(source_event_id,direction,phone,contact_name,occurred_at,user_phone,manager) VALUES(?,?,?,?,?,?,?)');
$insert->execute(['fixture-real-phone','outgoing','9892829842','Инесса Кораблева','2026-10-18 21:30:00','web-user-1','One']);
// Same contact phone under a different owner must never contribute to One's result.
$insert->execute(['fixture-real-phone-other-owner','incoming','9892829842','Инесса Кораблева','2026-10-18 21:30:00','web-user-2','Two']);
echo "Real-format phone fixture ready (local only)\n";
