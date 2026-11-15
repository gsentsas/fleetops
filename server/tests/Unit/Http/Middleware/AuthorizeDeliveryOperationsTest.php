<?php

use Fleetbase\FleetOps\Http\Middleware\AuthorizeDeliveryOperations;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class DeliveryPermissionFixture
{
    public static ?object $user;
    public static string $company = 'company-a';
    public static array $grants   = [];
    public static array $checked  = [];

    public static function getUserFromSession($request = null)
    {
        return self::$user;
    }

    public static function cannotUnlessAdmin(string $permission): bool
    {
        self::$checked[] = $permission;

        return !in_array($permission, self::$grants, true);
    }
}

if (!class_exists('Fleetbase\\Support\\Auth', false)) {
    class_alias(DeliveryPermissionFixture::class, 'Fleetbase\\Support\\Auth');
}

class DeliveryPermissionResponse
{
    public function json(array $body, int $status): object
    {
        return (object) ['status' => $status];
    }
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Middleware\\session')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Middleware; function session($key = null) { return \\DeliveryPermissionFixture::$company; }');
}
if (!function_exists('Fleetbase\\FleetOps\\Http\\Middleware\\response')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Middleware; function response() { return new \\DeliveryPermissionResponse(); }');
}

beforeEach(function () {
    DeliveryPermissionFixture::$user    = (object) ['status' => 'active'];
    DeliveryPermissionFixture::$company = 'company-a';
    DeliveryPermissionFixture::$grants  = [];
    DeliveryPermissionFixture::$checked = [];
});

function deliveryPermissionRequest(string $controller, string $action, string $method = 'POST'): Request
{
    $request = Request::create('/delivery-permission-fixture', $method);
    $handler = $controller . '@' . $action;
    $route   = new Route($method, '/delivery-permission-fixture', ['uses' => $handler, 'controller' => $handler]);
    $route->bind($request);
    $request->setRouteResolver(static fn () => $route);

    return $request;
}

$specializedOrderActions = [
    ['bulkDispatch', 'dispatch'],
    ['bulkAssignDriver', 'assign-driver-for'],
    ['importFromFiles', 'import'],
    ['editOrderRoute', 'update-route-for'],
    ['scheduleOrder', 'schedule'],
];

test('delivery gate accepts the specialized order permission without demanding generic update', function (string $method, string $action) {
    $permission                        = 'fleet-ops ' . $action . ' order';
    DeliveryPermissionFixture::$grants = [$permission];
    $request                           = deliveryPermissionRequest('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', $method);
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(200)
        ->and(DeliveryPermissionFixture::$checked)->toBe([$permission]);
})->with($specializedOrderActions);

test('generic order update does not authorize a specialized operation', function (string $method, string $action) {
    DeliveryPermissionFixture::$grants = ['fleet-ops update order'];
    $request                           = deliveryPermissionRequest('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', $method);
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(403);
})->with($specializedOrderActions);

test('hyphenated file upload action requires create permission', function () {
    DeliveryPermissionFixture::$grants = ['fleetbase create file'];
    $request                           = deliveryPermissionRequest('Fleetbase\\Http\\Controllers\\Internal\\v1\\FileController', 'upload-base64');
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(200)
        ->and(DeliveryPermissionFixture::$checked)->toBe(['fleetbase create file']);
});

test('existing order actions retain their native permission', function (string $method, string $httpMethod, string $action) {
    $permission                        = 'fleet-ops ' . $action . ' order';
    DeliveryPermissionFixture::$grants = [$permission];
    $request                           = deliveryPermissionRequest('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', $method, $httpMethod);
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(200)
        ->and(DeliveryPermissionFixture::$checked)->toBe([$permission]);
})->with([
    ['query', 'GET', 'list'],
    ['createRecord', 'POST', 'create'],
    ['deleteRecord', 'DELETE', 'delete'],
    ['bulkCancel', 'POST', 'cancel'],
    ['dispatchOrder', 'POST', 'dispatch'],
    ['capturePhoto', 'POST', 'update'],
    ['findRecord', 'GET', 'view'],
]);

test('unauthenticated or inactive actors cannot use specialized permissions', function (string $state) {
    if ($state === 'missing-user') {
        DeliveryPermissionFixture::$user = null;
    } elseif ($state === 'inactive-user') {
        DeliveryPermissionFixture::$user->status = 'inactive';
    } else {
        DeliveryPermissionFixture::$company = '';
    }
    DeliveryPermissionFixture::$grants = ['fleet-ops dispatch order'];
    $request                           = deliveryPermissionRequest('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', 'bulkDispatch');
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(401)
        ->and(DeliveryPermissionFixture::$checked)->toBe([]);
})->with(['missing-user', 'inactive-user', 'missing-company']);

test('file upload refuses an unrelated update permission', function () {
    DeliveryPermissionFixture::$grants = ['fleetbase update file'];
    $request                           = deliveryPermissionRequest('Fleetbase\\Http\\Controllers\\Internal\\v1\\FileController', 'upload-base64');
    $response                          = (new AuthorizeDeliveryOperations())->handle($request, static fn () => (object) ['status' => 200]);
    expect($response->status)->toBe(403)
        ->and(DeliveryPermissionFixture::$checked)->toBe(['fleetbase create file']);
});
