<?php
declare(strict_types=1);

/** Both serializers exist. Conflicting nonempty values never assign an owner. */
function clientManagerField(array $fields): array
{
    $values=[];
    foreach(['manager','Менеджер'] as $key){
        $value=$fields[$key]??null;
        if($value!==null&&!is_string($value))return ['label'=>'','conflict'=>true];
        $value=trim((string)$value);
        if($value!=='')$values[$key]=$value;
    }
    $keys=array_unique(array_map('clientManagerDesignationKey',array_values($values)));
    return ['label'=>count($keys)>1?'':(reset($values)?:''),'conflict'=>count($keys)>1];
}

/** Exact source designation; unlike managerNameKey, word order is retained. */
function clientManagerDesignation(string $label): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$label)??''));
}
function clientManagerDesignationKey(string $label): string
{
    return hash('sha256',clientManagerDesignation($label));
}
