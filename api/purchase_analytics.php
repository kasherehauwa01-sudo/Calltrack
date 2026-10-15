<?php
declare(strict_types=1);

/** Exact minor-unit arithmetic. Reject overflow instead of silently rounding. */
function purchaseCents(string $amount): int
{
    if (!preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/D',trim($amount),$m)) throw new InvalidArgumentException('Некорректная сумма продажи');
    $value=(int)$m[2]*100+(int)str_pad($m[3]??'',2,'0');
    return $m[1]==='-' ? -$value : $value;
}
function purchaseAdd(int $a,int $b): int
{
    $sum=$a+$b;
    if (!is_int($sum)) throw new OverflowException('Слишком большая сумма продаж');
    return $sum;
}
function purchaseMoney(int $cents): string
{
    return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
}
function purchaseDelta(int $current,int $previous): array
{
    $delta=purchaseAdd($current,-$previous);
    // Only the display percentage uses a ratio; all monetary arithmetic is integer.
    return ['absolute_change'=>purchaseMoney($delta),'percentage_change'=>$previous===0?null:round($delta/$previous*100,1)];
}
function purchaseDefaultPeriod(?DateTimeImmutable $today=null): array
{
    $month=($today??new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->modify('first day of this month');
    return ['from'=>$month->modify('-11 months')->format('Y-m'),'to'=>$month->format('Y-m'),'detail'=>'month'];
}
function purchaseMonth(string $value): DateTimeImmutable
{
    $date=DateTimeImmutable::createFromFormat('!Y-m',$value,new DateTimeZone('Europe/Moscow'));
    if (!$date || $date->format('Y-m')!==$value || (int)$date->format('Y')<2 || (int)$date->format('Y')>9998) throw new InvalidArgumentException('Укажите месяц и год периода');
    return $date;
}
function purchaseDataset(array $items): array
{
    $sales=[];
    foreach($items as $item){
        $id=(int)($item['sale_id']??0);$date=(string)($item['sale_date']??'');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if($id<=0 || !$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Некорректные данные продажи');
        if(isset($sales[$id])) continue;
        $item['amount_cents']=purchaseCents((string)$item['total_amount']);
        $item['total_amount']=purchaseMoney($item['amount_cents']);$sales[$id]=$item;
    }
    usort($sales,static fn($a,$b)=>strcmp($b['sale_date'],$a['sale_date']) ?: $a['sale_id']<=>$b['sale_id']);
    return $sales;
}
function purchaseTree(array $sales): array
{
    $years=[];
    foreach($sales as $sale){
        $date=$sale['sale_date'];$y=substr($date,0,4);$m=substr($date,0,7);$c=$sale['amount_cents'];
        if(!isset($years[$y]))$years[$y]=['key'=>(string)$y,'cents'=>0,'months'=>[]];
        $year=&$years[$y];$year['cents']=purchaseAdd($year['cents'],$c);
        if(!isset($year['months'][$m]))$year['months'][$m]=['key'=>$m,'cents'=>0,'dates'=>[]];
        $month=&$year['months'][$m];$month['cents']=purchaseAdd($month['cents'],$c);
        if(!isset($month['dates'][$date]))$month['dates'][$date]=['key'=>$date,'cents'=>0,'sales'=>[]];
        $day=&$month['dates'][$date];$day['cents']=purchaseAdd($day['cents'],$c);
        unset($sale['amount_cents']);$day['sales'][]=$sale;
        unset($year,$month,$day);
    }
    krsort($years);
    foreach($years as &$year){
        $year['total_amount']=purchaseMoney($year['cents']);unset($year['cents']);krsort($year['months']);
        foreach($year['months'] as &$month){
            $month['total_amount']=purchaseMoney($month['cents']);unset($month['cents']);krsort($month['dates']);
            foreach($month['dates'] as &$day){$day['total_amount']=purchaseMoney($day['cents']);unset($day['cents']);}unset($day);
            $month['dates']=array_values($month['dates']);
        }unset($month);$year['months']=array_values($year['months']);
    }unset($year);
    return array_values($years);
}
function purchaseAnalytics(array $sales,string $from,string $to,string $detail): array
{
    $start=purchaseMonth($from);$end=purchaseMonth($to);
    if($start>$end)throw new InvalidArgumentException('Начало периода должно быть не позже окончания');
    if(!in_array($detail,['day','month','year'],true))throw new InvalidArgumentException('Неизвестная детализация');
    $months=((int)$end->format('Y')-(int)$start->format('Y'))*12+(int)$end->format('m')-(int)$start->format('m')+1;
    if($months>1200 || ($detail==='day' && $months>24))throw new InvalidArgumentException('Выберите не более 24 месяцев по дням или 100 лет по месяцам/годам');
    $previousStart=$start->modify('-1 year');$previousEnd=$end->modify('-1 year')->modify('last day of this month');
    $end=$end->modify('last day of this month');
    $buckets=[];$currentMap=[];$previousMap=[];
    for($month=$start;$month<=$end;$month=$month->modify('+1 month')){
        $prev=$month->modify('-1 year');
        $days=$detail==='day'?max((int)$month->format('t'),(int)$prev->format('t')):1;
        for($d=1;$d<=$days;$d++){
            $format=$detail==='year'?'Y':($detail==='month'?'Y-m':'Y-m-d');
            $current=$detail==='day'?($d<=(int)$month->format('t')?$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$d)->format($format):null):$month->format($format);
            $previous=$detail==='day'?($d<=(int)$prev->format('t')?$prev->setDate((int)$prev->format('Y'),(int)$prev->format('m'),$d)->format($format):null):$prev->format($format);
            $key=$current??($month->format('Y-m').'-29');
            if(!isset($buckets[$key]))$buckets[$key]=['label'=>$current===null?'29.02 (только прошлый год)':$key,'current_date'=>$current,'previous_date'=>$previous,'current_cents'=>0,'previous_cents'=>0,'current_count'=>0,'previous_count'=>0];
            if($current!==null)$currentMap[$current]=$key;
            if($previous!==null)$previousMap[$previous]=$key;
        }
    }
    $currentTotal=0;$previousTotal=0;
    foreach($sales as $sale){
        $date=$sale['sale_date'];$key=substr($date,0,$detail==='day'?10:($detail==='month'?7:4));$c=$sale['amount_cents'];
        foreach(['current','previous'] as $series){
            $min=$series==='current'?$start:$previousStart;$max=$series==='current'?$end:$previousEnd;
            $map=$series==='current'?$currentMap:$previousMap;
            if($date<$min->format('Y-m-d') || $date>$max->format('Y-m-d') || !isset($map[$key]))continue;
            $bucket=&$buckets[$map[$key]];$bucket[$series.'_cents']=purchaseAdd($bucket[$series.'_cents'],$c);$bucket[$series.'_count']++;unset($bucket);
            if($series==='current')$currentTotal=purchaseAdd($currentTotal,$c);else $previousTotal=purchaseAdd($previousTotal,$c);
        }
    }
    foreach($buckets as &$bucket){$bucket+=purchaseDelta($bucket['current_cents'],$bucket['previous_cents']);$bucket['current']=purchaseMoney($bucket['current_cents']);$bucket['previous']=purchaseMoney($bucket['previous_cents']);unset($bucket['current_cents'],$bucket['previous_cents']);}unset($bucket);
    return ['from'=>$start->format('Y-m-d'),'to'=>$end->format('Y-m-d'),'previous_from'=>$previousStart->format('Y-m-d'),'previous_to'=>$previousEnd->format('Y-m-d'),'detail'=>$detail,'buckets'=>array_values($buckets),'current_total'=>purchaseMoney($currentTotal),'previous_total'=>purchaseMoney($previousTotal)]+purchaseDelta($currentTotal,$previousTotal);
}
