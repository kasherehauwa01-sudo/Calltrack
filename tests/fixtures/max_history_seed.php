<?php
// Local integration fixture only. Refuse any database other than the explicitly isolated one.
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1')exit("Fixture disabled\n");
if(getenv('CALLTRACK_DB_NAME')!=='calltrack_max_history_test')throw new RuntimeException('Isolated DB required');
$pdo=new PDO('mysql:host='.getenv('CALLTRACK_DB_HOST').';dbname=calltrack_max_history_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='calltrack_max_history_test')throw new RuntimeException('Isolated DB required');
$pdo->exec(file_get_contents(__DIR__.'/../../database/create_calls_table.sql'));
$pdo->exec(file_get_contents(__DIR__.'/../../database/20261016_create_max_messages.sql'));
require __DIR__.'/client-workspace/seed.php';
// This seed is run once in a fresh disposable database.
$insert=$pdo->prepare('INSERT INTO max_messages(source_event_id,direction,phone,occurred_at,user_phone,manager) VALUES(?,?,?,?,?,?)');
for($i=0;$i<6;$i++)$insert->execute(['fixture-max-in-'.$i,'incoming','9992223344','2026-10-17 21:30:00','web-user-1','One']);
for($i=0;$i<4;$i++)$insert->execute(['fixture-max-out-'.$i,'outgoing','9992223355','2026-10-17 21:40:00','web-user-1','One']);
$insert->execute(['fixture-other-owner','incoming','9992223344','2026-10-17 21:30:00','web-user-2','Two']);
$insert->execute(['fixture-missing-phone','incoming',null,'2026-10-17 21:30:00','web-user-1','One']);
$insert->execute(['fixture-blank-phone','incoming','','2026-10-17 21:30:00','web-user-1','One']);
echo "MAX history fixture ready\n";
