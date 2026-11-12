<?php
declare(strict_types=1);
require '/lab/fork/security-tests/r77-http-kernel-cases.php';
$path='/int/v1/fleet-ops/navigator/pairings/redeem';
function preflight(
    \Illuminate\Contracts\Http\Kernel $kernel,
    string $origin
): array {
    $request=\Illuminate\Http\Request::create(
        '/int/v1/fleet-ops/navigator/pairings/approve', 'OPTIONS',[],[],[],
        [
            'HTTP_ORIGIN'=>$origin,'HTTP_ACCEPT'=>'application/json',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS'=>'content-type',
            'REMOTE_ADDR'=>'192.0.2.231',
        ]
    );
    if ($request->headers->get('Origin') !== $origin) throw new RuntimeException('Origin header not preserved');
    $response=$kernel->handle($request);
    return [
        $response->getStatusCode(),
        $response->headers->get('Access-Control-Allow-Origin',''),
        $response->headers->get('Access-Control-Allow-Credentials',''),
    ];
}
$allowed=preflight($kernel,'http://localhost:4200');
$denied=preflight($kernel,'https://untrusted.synthetic.invalid');
$good=$allowed[0]===204 && $allowed[1]==='http://localhost:4200'
    && $allowed[2]==='true';
// Browser enforces an exact Origin/ACAO match. A different allow-listed
// origin is safe even if the preflight sends that static ACAO value.
$bad=$denied[1] !== 'https://untrusted.synthetic.invalid';
echo 'R77_CORS_ALLOWLIST='.($good?'PASS':'FAIL')
    .' HTTP='.$allowed[0].' ACAO='.($allowed[1]?:'none')
    .' CREDENTIALS='.($allowed[2]?:'none').PHP_EOL;
echo 'R77_CORS_UNTRUSTED_ORIGIN_NOT_ECHOED='.($bad?'PASS':'FAIL')
    .' HTTP='.$denied[0].' ACAO='.($denied[1]?:'none')
    .' CREDENTIALS='.($denied[2]?:'none').PHP_EOL;

$ip='192.0.2.242';
$codes=[];
$clientIps=[];
for($i=0;$i<12;$i++) {
    $request=\Illuminate\Http\Request::create($path,'POST',[],[],[],
        [
          'CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
          'REMOTE_ADDR'=>$ip,
          'HTTP_X_FORWARDED_FOR'=>'198.51.100.'.(10+$i),
          'HTTP_X_FORWARDED_PROTO'=>'https',
        ],
        json_encode(['pairingSessionId'=>str_repeat('c',64),
            'pairingSecret'=>str_repeat('c',64),'publicKeyPem'=>'invalid'])
    );
    $clientIps[]=$request->ip();
    $response=$kernel->handle($request);
    $codes[]=$response->getStatusCode();
}
$expected=array_merge(array_fill(0,10,403),[429,429]);
$xff=$codes===$expected;
echo 'R77_XFF_SPOOF_THROTTLE='.($xff?'PASS':'FAIL')
    .' CODES='.json_encode(array_count_values($codes))
    .' TRUST_RESOLVED='.count(array_unique($clientIps)).PHP_EOL;
$passed=(int)$good+(int)$bad+(int)$xff;
echo 'R77_REAL_HTTP_CORS_XFF_GATES='.$passed.'/3'.PHP_EOL;
if ($passed!==3) exit(7);
