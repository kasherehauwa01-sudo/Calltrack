<?php
declare(strict_types=1);
/** Diagnostic pagination is disposable, and never advances an enabled DB cursor. */
function analyticsPreviewLoad(PDO $db,array $policy,string $table):array {
 if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return [null,null,null,null];
 $passCache=clientCatalogRevision();$effectivePolicy=$policy;unset($effectivePolicy['cache_revision']);
 $authority=['policy'=>$effectivePolicy,'day'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('Y-m-d')];
 foreach(['analytics_client_binding_events','client_manager_binding_events'] as $events)$authority[$events]=$db->query('SELECT COALESCE(MAX(id),0) FROM '.$events)->fetchColumn();
 $authority['task_policy']=analyticsTaskPolicy($db)['revision'];
 try{$authority['budget']=analyticsBudget($db);}catch(Throwable $e){$authority['budget']='UNAVAILABLE';}
 try{$s=$db->prepare('SELECT used_tasks FROM analytics_daily_budget WHERE policy_id=1 AND business_date=?');$s->execute([$authority['day']]);$authority['daily_used']=$s->fetchColumn();}catch(Throwable $e){$authority['daily_used']='UNAVAILABLE';}
 $authority['keyrings']=hash('sha256',(getenv('CALLTRACK_UPSTREAM_PUBLIC_KEYS')?:'')."\0".(getenv('CALLTRACK_BINDINGS_PUBLIC_KEYS')?:''));
 $fingerprint=hash('sha256',json_encode(analyticsCanonical($authority),JSON_THROW_ON_ERROR));
 $directory=clientsEnsureDirectory(clientsProjectStorageDirectory().'/cache/analytics-preview');
 $file=$directory.'/'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()."\0".$table).'.json';
 $state=['id'=>1,'publication_sequence'=>0,'current_run_id'=>null,'current_sequence'=>null,'after_change'=>0];
 if(is_file($file)){
  if(filesize($file)>8192)throw new RuntimeException('Preview cursor exceeds bound');
  $saved=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
  if(($saved['version']??null)!==1||!is_array($saved['state']??null))throw new RuntimeException('Invalid preview cursor');
  if(($saved['authority']??null)===$fingerprint){$candidate=$saved['state'];
   foreach(['publication_sequence','after_change'] as $field)if(!is_int($candidate[$field]??null)||$candidate[$field]<0)throw new RuntimeException('Invalid preview cursor position');
   if(!array_key_exists('current_run_id',$candidate)||!array_key_exists('current_sequence',$candidate)||(($candidate['current_run_id']===null)!==($candidate['current_sequence']===null)))throw new RuntimeException('Invalid preview run');
   if($candidate['current_run_id']!==null&&(!is_string($candidate['current_run_id'])||!preg_match('/^[a-zA-Z0-9-]{1,36}$/D',$candidate['current_run_id'])||!is_int($candidate['current_sequence'])||$candidate['current_sequence']<1))throw new RuntimeException('Invalid preview run reference');
   // Finish the bounded diagnostic pass despite unrelated cache polling. A changed
   // snapshot starts another pass after completion; every event still gates live.
   if($candidate['current_run_id']!==null||($saved['cache_revision']??null)===$passCache){$state=$candidate;$passCache=$saved['cache_revision']??$passCache;}
  }
 }
 return [$state,$file,$fingerprint,$passCache];
}
function analyticsPreviewSave(?string $file,?string $fingerprint,?array $state,?string $passCache):void {
 if($file===null||$state===null)return;
 $temporary=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
 try{
  if(file_put_contents($temporary,json_encode(['version'=>1,'authority'=>$fingerprint,'cache_revision'=>$passCache,'state'=>$state],JSON_THROW_ON_ERROR),LOCK_EX)===false||!chmod($temporary,0600)||!rename($temporary,$file))throw new RuntimeException('Preview checkpoint write failed');
 }finally{if(is_file($temporary))unlink($temporary);}
}
