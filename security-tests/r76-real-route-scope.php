<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
use Fleetbase\Support\NavigatorCredentialScope;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function probe(string $method, string $controllerAction, array $body = []): bool
{
    $request = Request::create('/synthetic/fleet-ops/navigator', $method, $body);
    $route = new Route($method, '/synthetic/fleet-ops/navigator', ['controller'=>$controllerAction,'uses'=>$controllerAction]);
    $request->setRouteResolver(static fn () => $route);
    return NavigatorCredentialScope::allowsBootstrap($request);
}
function gate(string $name,bool $real,bool $expected):void
{
    if ($real !== $expected) throw new RuntimeException($name);
    echo 'R76_SCOPE_'.$name.'=PASS'.PHP_EOL;
}
$api='Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\';
$internal='Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\';
gate('POST_DRIVER_LOGIN_ALLOWED',probe('POST',$api.'DriverController@login'),true);
gate('POST_DRIVER_PHONE_ALLOWED',probe('POST',$api.'DriverController@loginWithPhone'),true);
gate('POST_VERIFY_DRIVER_ALLOWED',probe('POST',$api.'DriverController@verifyCode',['for'=>'driver_login']),true);
gate('POST_VERIFY_DEFAULT_DRIVER_ALLOWED',probe('POST',$api.'DriverController@verifyCode'),true);
gate('POST_VERIFY_CREATE_ACCOUNT_DENIED',probe('POST',$api.'DriverController@verifyCode',['for'=>'account_creation']),false);
gate('POST_VERIFY_ADMIN_DENIED',probe('POST',$api.'DriverController@verifyCode',['for'=>'admin']),false);
gate('GET_DRIVER_ONBOARD_SETTINGS_ALLOWED',probe('GET',$api.'NavigatorController@getDriverOnboardSettings'),true);
gate('POST_DRIVER_ONBOARD_SETTINGS_DENIED',probe('POST',$api.'NavigatorController@getDriverOnboardSettings'),false);
gate('GET_DRIVER_LOGIN_DENIED',probe('GET',$api.'DriverController@login'),false);
gate('GET_ADMIN_SETTINGS_DENIED',probe('GET',$internal.'NavigatorController@pendingPairings'),false);
gate('POST_ADMIN_APPROVE_DENIED',probe('POST',$internal.'NavigatorController@approvePairing'),false);
gate('GET_ORDER_LIST_DENIED',probe('GET',$api.'OrderController@query'),false);
gate('POST_DRIVER_OTHER_ACTION_DENIED',probe('POST',$api.'DriverController@register'),false);
gate('POST_UNRELATED_CONTROLLER_DENIED',probe('POST','Fleetbase\\Http\\Controllers\\Internal\\v1\\UserController@create'),false);
echo 'R76_SCOPE_ROUTE_GATES=14/14'.PHP_EOL;
