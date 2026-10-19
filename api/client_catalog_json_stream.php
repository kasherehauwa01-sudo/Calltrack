<?php
declare(strict_types=1);

/** Legacy JSON array/object reader: retain at most one top-level entry and a 64 KiB block. */
final class ClientCatalogJsonStream
{
    private $handle;private string $buffer='';private int $offset=0;
    public function __construct(string $file){$this->handle=@fopen($file,'rb');if($this->handle===false)throw new RuntimeException('Не удалось прочитать справочник');}
    public function __destruct(){if(is_resource($this->handle))fclose($this->handle);}
    private function peek(): ?string{
        if($this->offset>=strlen($this->buffer)){$block=fread($this->handle,65536);if($block===false)throw new RuntimeException('Ошибка чтения справочника');$this->buffer=$block;$this->offset=0;if($block==='')return null;}
        return $this->buffer[$this->offset];
    }
    private function take(): ?string{$c=$this->peek();if($c!==null)$this->offset++;return $c;}
    private function space():void{while(($c=$this->peek())!==null&&str_contains(" \r\n\t",$c))$this->take();}
    private function quoted():string{
        $this->space();if($this->take()!=='"')throw new RuntimeException('Некорректный ключ справочника');$raw='"';$escaped=false;
        while(($c=$this->take())!==null){$raw.=$c;if(!$escaped&&$c==='"')return json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!$escaped&&$c==='\\')$escaped=true;else $escaped=false;if(strlen($raw)>4096)throw new RuntimeException('Слишком длинный ключ');}
        throw new RuntimeException('Незавершённый ключ');
    }
    public function rows(): Generator{
        $this->space();$open=$this->take();if($open!=='['&&$open!=='{')throw new RuntimeException('Некорректный формат справочника');$close=$open==='['?']':'}';
        $this->space();if($this->peek()===$close){$this->take();$this->space();if($this->peek()!==null)throw new RuntimeException('Лишние данные');return;}
        while(true){
            if($open==='{'){$this->quoted();$this->space();if($this->take()!==':')throw new RuntimeException('Некорректный объект');}
            $this->space();$raw='';$depth=0;$quote=false;$escape=false;
            while(($c=$this->peek())!==null){
                if(!$quote&&$depth===0&&str_contains(',]}',$c))break;
                $raw.=$this->take();if(strlen($raw)>4*1024*1024)throw new RuntimeException('Слишком большая отдельная карточка');
                if($quote){if($escape)$escape=false;elseif($c==='\\')$escape=true;elseif($c==='"')$quote=false;}
                elseif($c==='"')$quote=true;elseif($c==='{'||$c==='[')$depth++;elseif($c==='}'||$c===']')$depth--;
            }
            $row=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($row))throw new RuntimeException('Некорректная карточка');yield $row;
            $this->space();$separator=$this->take();if($separator===$close)break;if($separator!==',')throw new RuntimeException('Некорректный разделитель');
        }
        $this->space();if($this->peek()!==null)throw new RuntimeException('Лишние данные');
    }
}
function salesBuyerDirectoryRows(): Generator
{
    if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())){foreach(clientsOpenSqlite()->query('SELECT payload_json FROM clients') as $row)yield json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);return;}
    if(is_file(clientsRecordsReadyFile())){foreach(glob(clientsRecordsDirectory().'/*.json')?:[] as $file)yield from (new ClientCatalogJsonStream($file))->rows();return;}
    if(is_file(clientsCacheFile()))yield from (new ClientCatalogJsonStream(clientsCacheFile()))->rows();
}
