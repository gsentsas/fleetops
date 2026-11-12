<?php
declare(strict_types=1);
require '/lab/fork/security-tests/r77-http-kernel-cases.php';
// This is the same real Laravel kernel, real installed throttle middleware,
// and same in-memory cache driver throughout this isolated CLI process.
function exerciseThrottle(
    \Illuminate\Contracts\Http\Kernel $kernel,
    string $path,
    string $ip,
    int $limit,
    string $label
): bool {
    $canary=str_repeat('d',64);
    $codes=[];
    for($i=1;$i<=$limit+2;$i++) {
        $request=\Illuminate\Http\Request::create($path,'POST',[],[],[],[
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'REMOTE_ADDR'=>$ip,
        ],json_encode(['pairingSessionId'=>$canary,'pairingSecret'=>$canary,
            'signature'=>'synthetic','publicKeyPem'=>'synthetic']));
        $reply=$kernel->handle($request);
        if(str_contains((string)$reply->getContent(),$canary)) {
            echo 'R77_'.$label.'_LEAK=FAIL'.PHP_EOL;
            return false;
        }
        $codes[]=$reply->getStatusCode();
    }
    $expected=array_merge(array_fill(0,$limit,403),[429,429]);
    $ok=$codes===$expected;
    $counts=array_count_values($codes);
    echo 'R77_'.$label.'_CODES='.json_encode($counts).' '.($ok?'PASS':'FAIL').PHP_EOL;
    return $ok;
}
$base='/int/v1/fleet-ops/navigator';
$checks=[
  exerciseThrottle($kernel,$base.'/pairings/redeem','192.0.2.88',10,'REAL_THROTTLE_REDEEM_10'),
  exerciseThrottle($kernel,$base.'/pairings/status','192.0.2.89',20,'REAL_THROTTLE_STATUS_20'),
  exerciseThrottle($kernel,$base.'/pairings/claim','192.0.2.90',10,'REAL_THROTTLE_CLAIM_10'),
];
$passed=count(array_filter($checks));
echo 'R77_REAL_LARAVEL_THROTTLE_GATES='.$passed.'/'.count($checks).PHP_EOL;
if($passed!==count($checks)) exit(5);
