<?php
declare(strict_types=1);

/** Display names only. Never use this short-lived cache for authorization or automation. */
function taskViewClients(array $ids): array {
    if(!$ids)return [];
    sort($ids,SORT_STRING);
    $key='task-client-names-v1|'.clientCatalogRevision().'|'.hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
    return clientCatalogCachedArray($key,static function()use($ids):array {
        if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())) {
            $records=analyticsDirectoryRecords($ids);
        } elseif(is_file(clientsRecordsReadyFile())) {
            $wanted=array_fill_keys($ids,true);$files=[];$records=[];$duplicates=[];
            foreach($ids as $id)$files[clientsRecordShardFile((string)$id)]=true;
            // Read only shards containing this page's IDs, not the entire catalogue.
            foreach(array_keys($files) as $file) {
                if(!is_file($file))continue;
                foreach((new ClientCatalogJsonStream($file))->rows() as $row) {
                    $id=(string)($row['id']??$row['fields']['id']??'');
                    if(!isset($wanted[$id]))continue;
                    if(isset($records[$id]))$duplicates[$id]=true;
                    $records[$id]=['name'=>(string)($row['name']??'')];
                }
            }
            foreach($duplicates as $id=>$_)unset($records[$id]);
        } else {
            $records=analyticsDirectoryRecords($ids);
        }
        $names=[];
        foreach($records as $id=>$record)$names[$id]=['name'=>(string)$record['name']];
        return $names;
    },60);
}
