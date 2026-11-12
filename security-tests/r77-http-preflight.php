<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
    $prefix = 'Fleetbase\\FleetOps\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = '/lab/fork/server/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
}, true, true);
$app=require '/fleetbase/api/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
echo 'R77_KERNEL_BOOT_LARAVEL=' . $app->version() . PHP_EOL;
echo 'R77_FEATURE_DEFAULT=' . (config('fleetops.navigator.pairing_v2_enabled',false)===true?'ON':'OFF') . PHP_EOL;
$routes=\Illuminate\Support\Facades\Route::getRoutes();
$matching=[];
foreach($routes as $route) {
    $uri=$route->uri();
    if (str_contains($uri,'fleet-ops/navigator')) {
        $matching[]=implode(',', $route->methods()) . ' ' . $uri . ' ' . $route->getActionName();
    }
}
echo 'R77_REGISTERED_NAVIGATOR_ROUTE_COUNT='.count($matching).PHP_EOL;
foreach($matching as $route){echo 'R77_ROUTE=' . $route . PHP_EOL;}
echo 'R77_HTTP_MIDDLEWARE_ALIASES='.implode(',',array_filter([
    $app['router']->getMiddleware()['fleetbase.protected'] ?? null,
    $app['router']->getMiddleware()['fleetbase.api'] ?? null,
])).PHP_EOL;
