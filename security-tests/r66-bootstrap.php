<?php
declare(strict_types=1);
// Disposable, network-isolated application test harness; not loaded by any server.
require '/fleetbase/api/vendor/autoload.php';
require '/lab/tools/vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
    $prefix = 'Fleetbase\\FleetOps\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $suffix = substr($class, strlen($prefix));
    $path = '/lab/fork/server/src/' . str_replace('\\', '/', $suffix) . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

// Bind a synthetic response factory only inside this throwaway CLI process.
// The actual Laravel JSON responses are still constructed by the framework.
$container = new \Illuminate\Container\Container();
\Illuminate\Container\Container::setInstance($container);
$container->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
    public function json(array $data, int $status = 200) {
        return new \Illuminate\Http\JsonResponse($data, $status);
    }
    public function error(string $message, int $status = 400) {
        return new \Illuminate\Http\JsonResponse(['error' => $message], $status);
    }
});
