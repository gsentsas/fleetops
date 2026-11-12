<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
// Runtime overrides exist only in this disposable HTTP server, never in source config.
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function () {
    config([
        'fleetops.navigator.pairing_v2_enabled' => true,
        'cors.allowed_origins' => ['https://console.lab.test'],
        'cors.supports_credentials' => true,
    ]);
});
// handle() binds the current Request BEFORE providers boot. Booting above it
// would make facade-target expansions fail and be silently skipped by Core.
$response = $kernel->handle($request = \Illuminate\Http\Request::capture());
$response->send();
$kernel->terminate($request, $response);
