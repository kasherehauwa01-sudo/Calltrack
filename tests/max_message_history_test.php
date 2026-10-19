<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/max_message_history.php';
function mhCheck(bool $condition,string $label): void { if(!$condition)throw new RuntimeException($label); }
$a=['client_id'=>'a','name'=>'Client A','phone'=>'+79991111111','fields'=>['Телефоны'=>'+79991111111, 89992222222']];
$b=['client_id'=>'b','name'=>'Client B','phone'=>'9993333333'];
$lookup=static fn($phone)=>match($phone){'9991111111'=>[$a],'9992222222'=>[$a],'9993333333'=>[$a,$b],default=>[]};
mhCheck(maxHistoryPhones(['matches'=>[$a]],$lookup)===['9991111111','9992222222'],'all unambiguous phones of stable client');
mhCheck(maxHistoryPhones(['matches'=>[$a,$b]],$lookup)===[],'ambiguous client context excluded');
mhCheck(maxHistoryPhones(['matches'=>[['name'=>'Client A','phone'=>'9991111111']]],$lookup)===[],'name alone excluded');
mhCheck(maxHistoryPhones(['matches'=>[]],$lookup)===[],'missing identity excluded');
$shared=$a;$shared['fields']['Телефоны']='9991111111, 9993333333';
mhCheck(maxHistoryPhones(['matches'=>[$shared]],$lookup)===['9991111111','9992222222'],'shared phone excluded, not assigned by name');
$detail=['id'=>'a','name'=>'Client A','phone'=>'+79991111111'];
mhCheck(maxHistoryPhones(['matches'=>[$detail]],$lookup)===['9991111111','9992222222'],'directory detail id supported');
try{maxHistoryPhones(['matches'=>[$a]],static fn()=>null);throw new RuntimeException('unavailable cache accepted');}catch(RuntimeException $e){mhCheck($e->getMessage()==='Client directory unavailable','cache failure fails closed');}
$emailMatches=maxHistoryEmailMatches('same@example.test',[
 ['id'=>'a','name'=>'Same Name','fields'=>['email'=>'same@example.test','phones'=>'9991111111']],
 ['id'=>'b','name'=>'Same Name','fields'=>['email'=>'same@example.test','phones'=>'9992222222']]
]);
mhCheck(count($emailMatches)===2,'same-name clients sharing email retain both stable identities');
mhCheck(maxHistoryPhones(['matches'=>$emailMatches],$lookup)===[],'ambiguous email context cannot choose by name');
$interval=maxHistoryIntervals('2026-10-19','2026-10-19');
mhCheck($interval===[['from'=>'2026-10-18 21:00:00','to'=>'2026-10-19 21:00:00','offset'=>10800]],'business midnight UTC boundaries');
foreach([['2026-02-30','2026-03-01'],['2026-10-20','2026-10-19'],['bad','bad']] as [$from,$to]){
 try{maxHistoryIntervals($from,$to);throw new RuntimeException('invalid date accepted');}catch(InvalidArgumentException $e){}
}
echo "max_message_history_test: OK\n";
