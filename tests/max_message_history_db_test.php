<?php
declare(strict_types=1);
$dsn=getenv('MAX_SYNC_TEST_DSN')?:'';
if(!str_ends_with($dsn,'dbname=calltrack_max_sync_test')){echo "max_message_history_db_test: SKIP (isolated DSN required)\n";exit(0);}
require_once dirname(__DIR__).'/api/max_message_history.php';
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/20261016_create_max_messages.sql'));
function dbHistoryCheck(bool $condition,string $label): void {if(!$condition)throw new RuntimeException($label);}
$prefix='history-test-'.bin2hex(random_bytes(6));$owner=$prefix.'-A';$other=$prefix.'-B';
$insert=$pdo->prepare('INSERT INTO max_messages(source_event_id,direction,phone,occurred_at,user_phone) VALUES(?,?,?,?,?)');$n=0;
$add=static function($direction,$phone,$stamp,$who=null)use($insert,$prefix,$owner,&$n){$insert->execute([$prefix.'-'.++$n,$direction,$phone,$stamp,$who??$owner]);};
try{
 for($i=0;$i<6;$i++)$add('incoming','9991111111','2026-10-18 10:00:00');
 for($i=0;$i<4;$i++)$add('outgoing','9992222222','2026-10-18 12:15:30');
 $rows=maxHistoryDaily($pdo,$owner,['9991111111','9992222222'],'2026-10-18','2026-10-18');
 dbHistoryCheck($rows===[['date'=>'2026-10-18','incoming_count'=>6,'outgoing_count'=>4,'last_event_at'=>'2026-10-18T12:15:30Z']],'one card 6/4 across two phones');
 for($i=0;$i<3;$i++)$add('incoming','9991111111','2026-10-18 21:30:00');
 for($i=0;$i<5;$i++)$add('outgoing','9991111111','2026-10-19 22:00:00');
 $add('incoming',null,'2026-10-18 12:00:00');$add('outgoing','','2026-10-18 12:00:00');
 $add('incoming','9991111111','2026-10-18 12:00:00',$other);$add('incoming','9993333333','2026-10-18 12:00:00');
 $days=maxHistoryDaily($pdo,$owner,['9991111111','9992222222'],'2026-10-18','2026-10-20');
 dbHistoryCheck(count($days)===3,'three local days');
 dbHistoryCheck($days[1]===['date'=>'2026-10-19','incoming_count'=>3,'outgoing_count'=>0,'last_event_at'=>'2026-10-18T21:30:00Z'],'UTC midnight and incoming only');
 dbHistoryCheck($days[2]['incoming_count']===0&&$days[2]['outgoing_count']===5,'outgoing only');
 dbHistoryCheck($days[0]['incoming_count']===6&&$days[0]['outgoing_count']===4,'null/blank/other client/owner excluded');
 dbHistoryCheck(maxHistoryDaily($pdo,$other,['9991111111'],'2026-10-18','2026-10-18')[0]['incoming_count']===1,'owner B isolated');
 dbHistoryCheck(maxHistoryDaily($pdo,$owner,['9993333333'],'2026-10-18','2026-10-18')[0]['incoming_count']===1,'client B separated');
 foreach($days as $row)dbHistoryCheck(array_keys($row)===['date','incoming_count','outgoing_count','last_event_at'],'aggregate-only response');
 // Repeated source_event_id cannot increase counts.
 try{$insert->execute([$prefix.'-1','incoming','9991111111','2026-10-18 10:00:00',$owner]);}catch(PDOException $e){dbHistoryCheck($e->getCode()==='23000','unique event constraint');}
 dbHistoryCheck(maxHistoryDaily($pdo,$owner,['9991111111','9992222222'],'2026-10-18','2026-10-18')[0]['incoming_count']===6,'no duplicate increment');
 // A historical business-offset change can split one local day into two SQL segments.
 $add('incoming','9991111111','2020-12-26 21:30:00');$add('outgoing','9992222222','2020-12-26 22:30:00');
 $transition=maxHistoryDaily($pdo,$owner,['9991111111','9992222222'],'2020-12-27','2020-12-27');
 dbHistoryCheck($transition===[['date'=>'2020-12-27','incoming_count'=>1,'outgoing_count'=>1,'last_event_at'=>'2020-12-26T22:30:00Z']],'offset segments merge into one local day');
 // Representative extra rows let EXPLAIN assess selectivity, without changing any production data.
 $pdo->beginTransaction();for($i=0;$i<5000;$i++)$add('incoming','988'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'2026-10-18 10:00:00',$other);$pdo->commit();
 [$sql,$params]=maxHistoryQuery($owner,['9991111111','9992222222'],'2026-10-18','2026-10-20');
 $plan=$pdo->prepare('EXPLAIN '.$sql);$plan->execute($params);echo json_encode($plan->fetchAll(PDO::FETCH_ASSOC),JSON_PRETTY_PRINT)."\n";
 echo "max_message_history_db_test: OK\n";
}finally{
 if($pdo->inTransaction())$pdo->rollBack();
 $pdo->prepare('DELETE FROM max_messages WHERE source_event_id LIKE ?')->execute([$prefix.'%']);
}
