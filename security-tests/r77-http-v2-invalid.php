<?php
declare(strict_types=1);
// Full real HTTP kernel bootstrap + OFF verification, then a controlled
// in-memory flag ON to exercise validation only; no real credential issued.
require '/lab/fork/security-tests/r77-http-kernel-cases.php';
config()->set('fleetops.navigator.pairing_v2_enabled', true);
$canary=str_repeat('a',64);
$cases=[
 ['LEGACY_GET_STILL_REFUSED','GET','/link-app?key='.$canary,null,403],
 ['QUERY_BEARER_REDEEM_REFUSED','POST','/pairings/redeem?key='.$canary,
  ['pairingSecret'=>$canary,'publicKeyPem'=>'invalid'],403],
 ['REDEEM_BAD_PUBLIC_KEY_REFUSED','POST','/pairings/redeem',
  ['pairingSecret'=>$canary,'publicKeyPem'=>'invalid'],403],
 ['REDEEM_UPPERCASE_TOKEN_REFUSED','POST','/pairings/redeem',
  ['pairingSecret'=>strtoupper($canary),'publicKeyPem'=>'invalid'],403],
 ['PROOF_BAD_SESSION_REFUSED','POST','/pairings/proof',
  ['pairingSessionId'=>'invalid','signature'=>'invalid'],403],
 ['STATUS_BAD_SESSION_REFUSED','POST','/pairings/status',
  ['pairingSessionId'=>'invalid'],403],
 ['CLAIM_BAD_SESSION_REFUSED','POST','/pairings/claim',
  ['pairingSessionId'=>'invalid','signature'=>'invalid'],403],
 ['ADMIN_APPROVE_ANONYMOUS_REFUSED','POST','/pairings/approve',
  ['pairingSessionId'=>$canary,'deviceConfirmationCode'=>'aaaaaaaaaaaa'],401],
];
$passed=0;
foreach($cases as [$label,$method,$uri,$payload,$expected]) {
    $request=\Illuminate\Http\Request::create(
        '/int/v1/fleet-ops/navigator'.$uri,$method,[],[],[],
        ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
            'HTTP_ORIGIN'=>'https://untrusted.synthetic.invalid',
            'REMOTE_ADDR'=>'192.0.2.77'],
        $payload===null?null:json_encode($payload)
    );
    $response=$kernel->handle($request);
    $status=$response->getStatusCode();
    $safe=!str_contains((string)$response->getContent(),$canary);
    $ok=$status===$expected && $safe;
    echo 'R77_ON_'.$label.'_STATUS='.$status.' '.($ok?'PASS':'FAIL').PHP_EOL;
    if($ok)$passed++;
}
echo 'R77_ON_FLAG_INVALID_HTTP_GATES='.$passed.'/'.count($cases).PHP_EOL;
if($passed!==count($cases))exit(6);
