<?php
declare(strict_types=1);
/** Hypothetical consumer-only evidence. NEVER a producer promotion helper. */
function phase1ConsumerFixture(array $event,string $client,int $manager,string $validUntil):array {
 $event['subject']=['kind'=>'external_id','version'=>1,'key'=>$client];
 $event['assignment']=['client_id'=>$client,'manager_id'=>$manager,'binding_revision'=>1];
 $event['run']['source_decision']=array_replace($event['run']['source_decision'],['method'=>'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','proof_hash'=>str_repeat('a',64),'policy_revision'=>1,'valid_until'=>$validUntil]);
 return $event;
}
