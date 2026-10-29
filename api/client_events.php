<?php
declare(strict_types=1);
require_once __DIR__.'/sales_buyer_catalog.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sendJson(['status'=>'error','message'=>'Разрешён только POST'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$input=readJsonBody();
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(array_key_exists('sales_client_key',$input)){
        $resolved=salesBuyerResolve($pdo,$user,$input);$b=$resolved['buyer'];$contact=$resolved['contact'];
        $record=['id'=>$b['id'],'name'=>$b['name'],'phones'=>$contact['phones'],'manager'=>$b['manager']];
        $owner=$b['owner_id']?['id'=>$b['owner_id'],'display_name'=>$b['manager']]:null;
        $phoneIndex=array_fill_keys($contact['phones'],$record['id']);$emailIndex=array_fill_keys($contact['emails'],$record['id']);
    }else{
    if(!is_string($input['client_id']??null)||trim($input['client_id'])==='')throw new InvalidArgumentException('Передайте client_id');
    $resolved=clientCatalogResolve($pdo,$user,trim($input['client_id']));$record=$resolved['record'];$owner=$resolved['owner'];
    $phoneIndex=$resolved['phone_index'];$emailIndex=$resolved['email_index'];
    }
    $calls=[];$emails=[];
    if($owner){
        require_once __DIR__.'/personal_contacts_filter.php';
        $stmt=$pdo->prepare('SELECT id_db,call_date,call_time,phone,call_type,duration,manager,comment,tag,reminder,reminder_text,source FROM calls WHERE user_phone=:owner AND '.workingCallsSql().' ORDER BY call_date DESC,call_time DESC,id_db DESC');
        $stmt->execute([':owner'=>webUserPhone((int)$owner['id'])]);
        foreach($stmt as $row)if(($phoneIndex[normalizeClientPhone((string)$row['phone'])]??null)===$record['id'])$calls[]=$row;
        $stmt=$pdo->query("SELECT e.id,e.sent_at,e.manager_name,e.client_email,e.to_emails,e.subject FROM email_messages e LEFT JOIN email_mailboxes m ON m.id=e.mailbox_id WHERE (e.direction='outgoing' OR (e.from_email<>'' AND LOWER(e.from_email)=LOWER(m.email))) ORDER BY e.sent_at DESC,e.id DESC");
        foreach($stmt as $row){
            if(managerNameKey((string)$row['manager_name'])!==managerNameKey((string)$owner['display_name']))continue;
            foreach(clientEmailsFromFields(['email'=>$row['client_email'].','.$row['to_emails']]) as $address){
                if(($emailIndex[$address]??null)===$record['id']){$emails[]=$row;break;}
            }
        }
    }
    sendJson(['status'=>'success','client'=>array_intersect_key($record,array_flip(['id','name','phones','manager']))+['manager_id'=>$owner?(string)$owner['id']:''],'calls'=>$calls,'emails'=>$emails]);
}catch(ClientCatalogAccessDenied $e){sendJson(['status'=>'error','message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Client events: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить историю клиента.'],503);}
