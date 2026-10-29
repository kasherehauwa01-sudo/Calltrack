<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_integration.php';
function analyticsEvidenceJson(array $payload):string {
 return json_encode(analyticsCanonical($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function analyticsKnownEvidenceText(mixed $value):bool {
 return is_string($value)&&trim($value)!==''&&!in_array(strtoupper(trim($value)),['UNKNOWN','UNSET','NULL','NONE'],true);
}
function analyticsVerifyEvidence(array $envelope,array $keys,string $issuer,string $audience,int $maxAge,int $now):array {
 if(array_diff(array_keys($envelope),['algorithm','key_id','payload_json','signature'])||count($envelope)!==4||($envelope['algorithm']??null)!=='Ed25519'||$maxAge<=0)throw new DomainException('Invalid evidence envelope');
 $raw=$envelope['payload_json']??null;$key=$keys[$envelope['key_id']??'']??null;
 if(!is_string($raw)||strlen($raw)>65536||!is_string($key)||!is_string($envelope['signature']??null))throw new DomainException('Evidence missing');
 $public=base64_decode($key,true);$signature=base64_decode($envelope['signature'],true);
 if($public===false||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||$signature===false||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($signature,$raw,$public))throw new DomainException('Signature invalid');
 $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($p)||analyticsEvidenceJson($p)!==$raw)throw new DomainException('Noncanonical or duplicate JSON');
 foreach(['issued_at','valid_until'] as $dateKey)if(!is_string($p[$dateKey]??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$p[$dateKey]))throw new DomainException('Explicit evidence timestamp required');
 $issued=strtotime($p['issued_at']);$until=strtotime($p['valid_until']);
 if(($p['version']??null)!==1||($p['issuer']??null)!==$issuer||($p['audience']??null)!==$audience||$issued===false||$until===false||$issued>$now||$until<=$now||$until<=$issued||$until-$issued>$maxAge||$now-$issued>$maxAge)throw new DomainException('Evidence expired or wrong audience');
 return $p;
}
function analyticsEvidenceKeys(string $variable):array {
 $raw=getenv($variable);if(!$raw)return [];$keys=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($keys)||array_is_list($keys)&&$keys!==[])throw new DomainException('Invalid public keyring');return $keys;
}
/** Calltrack signs identity review only. No upstream-source signing key is used here. */
function analyticsSignReview(array $payload):array {
 $file=getenv('CALLTRACK_BINDINGS_SIGNING_KEY_FILE');$id=getenv('CALLTRACK_BINDINGS_SIGNING_KEY_ID');
 if(!$file||!$id||!is_file($file)||filesize($file)>1024)throw new DomainException('Identity signer not configured');
 if(str_starts_with((string)realpath($file),realpath(__DIR__.'/..').DIRECTORY_SEPARATOR))throw new DomainException('Identity signing key must be outside web root');
 if((fileperms($file)&0007)!==0)throw new DomainException('Identity signing key is publicly readable');
 $key=base64_decode(trim((string)file_get_contents($file)),true);
 if($key===false||strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new DomainException('Invalid identity signing key');
 $raw=analyticsEvidenceJson($payload);$signature=sodium_crypto_sign_detached($raw,$key);sodium_memzero($key);
 return ['algorithm'=>'Ed25519','key_id'=>$id,'payload_json'=>$raw,'signature'=>base64_encode($signature)];
}
