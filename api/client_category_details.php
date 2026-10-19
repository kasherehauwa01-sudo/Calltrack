<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';

/** Names come only from an authorized, re-resolved context and selected client tags. */
function clientCategoryDetails(array $context,array $input,?callable $transport=null): array
{
    foreach(['category','subcategory'] as $key)if(array_key_exists($key,$input)&&(!is_string($input[$key])||$input[$key]===''||mb_strlen($input[$key])>512))throw new InvalidArgumentException('Некорректный путь категории');
    if(!isset($input['category']))throw new InvalidArgumentException('Выберите категорию');
    $names=array_values(array_unique(array_column(clientHistorySelectedClients($context),'name')));sort($names,SORT_STRING);
    $subcategory=$input['subcategory']??null;$limit=$subcategory===null?10:20;$level=$subcategory===null?'subcategories':'products';
    if(!$names)return ['level'=>$level,'items'=>[],'total_count'=>0,'parent_revenue'=>'0.00','parent_quantity'=>'0'];
    $request=['names'=>$names,'category'=>$input['category'],'subcategory'=>$subcategory];
    $key='category-details-v1|'.hash('sha256',json_encode($request,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $validate=static function(array $data)use($level,$input,$subcategory,$limit):array{
        if(($data['level']??null)!==$level||($data['category']??null)!==$input['category']||($data['subcategory']??null)!==$subcategory||!is_array($data['items']??null)||count($data['items'])>$limit||!is_int($data['total_count']??null)||$data['total_count']<count($data['items']))throw new RuntimeException('Некорректная детализация категории');
        $decimal=static fn($v)=>is_string($v)&&preg_match('/^-?\d+(?:\.\d+)?$/D',$v);
        foreach(['parent_revenue','parent_quantity'] as $field)if(!$decimal($data[$field]??null))throw new RuntimeException('Некорректный итог категории');
        foreach($data['items'] as $row)if(!is_array($row)||!is_string($row['name']??null)||!$decimal($row['revenue']??null)||!$decimal($row['quantity']??null))throw new RuntimeException('Некорректная строка категории');
        return $data;
    };
    $data=clientCatalogCachedArray($key,static function()use($request,$transport,$validate){
        try{return $validate(salesJournalRequest('POST','/api/integrations/calltrack/client-category-details',$request,$transport));}
        catch(RuntimeException $e){if($e->getMessage()==='Sales Journal HTTP 404')throw new RuntimeException('Обновите Sales Journal: детализация категорий ещё не установлена');throw $e;}
    },60);
    return $validate($data);
}
