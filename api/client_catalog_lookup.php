<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog_json_stream.php';

/** Retain one client, not the full decoded directory or its derived array cache. */
function clientCatalogLookup(string $id, ?callable $rows=null): array
{
    $targetRows=$rows ? $rows() : clientCatalogTargetRows($id);
    $rows??=static fn()=>salesBuyerDirectoryRows();
    $target=null;$matches=0;
    foreach($targetRows as $row){
        $rowId=(string)($row['id']??$row['fields']['id']??$row['fields']['client_id']??'');
        if($rowId!==$id)continue;
        $normalized=clientCatalogRecords([$row]);
        if(isset($normalized[$id])){$target=$normalized[$id];$matches++;}
    }
    if($matches!==1)throw new ClientCatalogAccessDenied('Клиент недоступен');
    $phones=array_fill_keys($target['phones'],$id);$emails=array_fill_keys($target['emails'],$id);
    $nameKey=static fn($s)=>mb_strtolower(trim(preg_replace('/\s+/u',' ',$s)??''));
    $name=$nameKey($target['name']);$unique=true;
    foreach($rows() as $row){
        foreach(clientCatalogRecords([$row]) as $other){
            if($other['id']===$id)continue;
            if($nameKey($other['name'])===$name)$unique=false;
            foreach($other['phones'] as $phone)unset($phones[$phone]);
            foreach($other['emails'] as $email)unset($emails[$email]);
        }
    }
    return ['record'=>$target,'phone_index'=>$phones,'email_index'=>$emails,'name_unique'=>$unique];
}

/** Existing directory indexes narrow the first pass to the requested identity. */
function clientCatalogTargetRows(string $id): iterable
{
    if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())){
        $db=new PDO('sqlite:'.clientsSqliteFile(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        $query=$db->prepare('SELECT payload_json FROM clients WHERE client_id=?');$query->execute([$id]);
        while($row=$query->fetch(PDO::FETCH_ASSOC))yield json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
    }elseif(is_file(clientsRecordsReadyFile())){
        $file=clientsRecordShardFile($id);
        if(is_file($file))yield from (new ClientCatalogJsonStream($file))->rows();
    }else{
        yield from salesBuyerDirectoryRows();
    }
}
