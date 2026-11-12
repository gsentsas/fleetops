<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
    $prefix = 'Fleetbase\\FleetOps\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = '/lab/fork/server/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
}, true, true);
$app = require '/fleetbase/api/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
// The package's normal extension registrar needs installation settings.
// In this isolated bootstrap, bind the REAL packaged Fleetbase Response
// expansion explicitly; never replace the controller or its methods.
if (!\Illuminate\Routing\ResponseFactory::hasMacro('error')) {
    \Illuminate\Routing\ResponseFactory::macro('error',
        (new \Fleetbase\Expansions\Response())->error());
}
echo 'R77_REAL_CORE_RESPONSE_EXPANSION='.
    (\Illuminate\Routing\ResponseFactory::hasMacro('error')?'PASS':'FAIL').PHP_EOL;

$configured = config('fleetops.navigator.pairing_v2_enabled', false);
echo 'R77_HTTP_FEATURE_OFF=' . ($configured === true ? 'FAIL' : 'PASS') . PHP_EOL;
echo 'R77_MAINTENANCE='.($app->isDownForMaintenance()?'ON':'OFF').PHP_EOL;
echo 'R77_HTTP_ROUTES='.count(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes()).PHP_EOL;
if ($configured === true) exit(4);
$base = '/int/v1/fleet-ops/navigator';
$canary = str_repeat('f',64);
$cases = [
 ['LEGACY_GET','GET','/link-app',null,403],
 ['LEGACY_QUERY_CANARY','GET','/link-app?key='.$canary,null,403],
 ['LEGACY_URL_GET','GET','/get-link-app',null,null],
 ['REDEEM_DISABLED','POST','/pairings/redeem',['pairingSecret'=>$canary,'publicKeyPem'=>'synthetic'],403],
 ['PROOF_DISABLED','POST','/pairings/proof',['pairingSessionId'=>$canary,'signature'=>'synthetic'],403],
 ['STATUS_DISABLED','POST','/pairings/status',['pairingSessionId'=>$canary],403],
 ['CLAIM_DISABLED','POST','/pairings/claim',['pairingSessionId'=>$canary,'signature'=>'synthetic'],403],
 ['ADMIN_ISSUE_ANONYMOUS','POST','/pairings',[],null],
 ['ADMIN_PENDING_ANONYMOUS','POST','/pairings/admin-pending',[],null],
 ['ADMIN_APPROVE_ANONYMOUS','POST','/pairings/approve',['pairingSessionId'=>$canary,'deviceConfirmationCode'=>'ffffffffffff'],null],
 ['ADMIN_REVOKE_ANONYMOUS','DELETE','/pairings/synthetic-credential',null,null],
 ['ADMIN_ROTATE_ANONYMOUS','POST','/pairings/synthetic-credential/rotate',[],null],
 ['GET_NOT_POST','GET','/pairings/redeem',null,405],
];
$pass=0;
foreach($cases as [$name,$method,$uri,$payload,$expected]) {
    $content=$payload===null ? null : json_encode($payload,JSON_THROW_ON_ERROR);
    $req=\Illuminate\Http\Request::create($base.$uri,$method,[],[],[],[
        'HTTP_ACCEPT'=>'application/json',
        'HTTP_ORIGIN'=>'https://untrusted.synthetic.invalid',
        'CONTENT_TYPE'=>'application/json',
        'REMOTE_ADDR'=>'192.0.2.55',
    ],$content);
    $response = $kernel->handle($req);
    $status=$response->getStatusCode();
    $text=(string)$response->getContent();
    $containsCanary=str_contains($text,$canary);
    if ($name==='LEGACY_GET') {
        echo 'R77_FIRST_BODY=' . substr(preg_replace('/[a-f0-9]{64}/', '[synthetic]', strip_tags($text)),0,320) . PHP_EOL;
        echo 'R77_FIRST_CONTENT_TYPE=' . $response->headers->get('Content-Type','none') . PHP_EOL;
        $json=json_decode($text,true);
        if(is_array($json)) {
            echo 'R77_FIRST_JSON_KEYS='.implode(',',array_keys($json)).PHP_EOL;
            foreach(['error','message','exception','file'] as $field) {
                if(isset($json[$field]) && is_string($json[$field]))
                    echo 'R77_FIRST_'.$field.'='.substr(preg_replace('/[a-f0-9]{64}/','[synthetic]',$json[$field]),0,220).PHP_EOL;
            }
        }
        $match=\Illuminate\Support\Facades\Route::getRoutes()->match($req);
        echo 'R77_FIRST_MIDDLEWARE='.implode(',', $match->gatherMiddleware()).PHP_EOL;

    }

    $result = !$containsCanary
        && ($expected===null ? $status===401||$status===403 : $status===$expected);
    echo 'R77_HTTP_'.$name.'_STATUS='.$status.' '.($result?'PASS':'FAIL')
      .' CACHE='.($response->headers->get('Cache-Control') ?? 'none')
      .' REFERRER='.($response->headers->get('Referrer-Policy') ?? 'none')
      .PHP_EOL;
    if($result)$pass++;
}
echo 'R77_REAL_KERNEL_HTTP_GATES='.$pass.'/'.count($cases).PHP_EOL;
if($pass!==count($cases))exit(3);
