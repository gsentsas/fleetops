<?php

namespace Fleetbase\FleetOps\Http\Middleware;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\File;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Native permission checks for delivery controllers without a resource contract. */
class AuthorizeDeliveryOperations
{
    public function handle(Request $request, \Closure $next)
    {
        $route      = $request->route();
        $controller = $route?->getControllerClass();
        $isOrder    = in_array($controller, [
            \Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class,
            \Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController::class,
        ], true);
        $isFile = in_array($controller, [
            \Fleetbase\Http\Controllers\Api\v1\FileController::class,
            \Fleetbase\Http\Controllers\Internal\v1\FileController::class,
        ], true);
        if (!$isOrder && !$isFile) {
            return $next($request);
        }
        $user = Auth::getUserFromSession($request);
        if (!$user || !is_string(session('company')) || session('company') === '' || $user->status !== 'active') {
            return response()->json(['errors' => ['Authentication required.']], 401);
        }
        $method = Str::camel($route->getActionMethod());
        $action = match ($method) {
            'query', 'queryRecord', 'search', 'searchRecords'                      => 'list',
            'create', 'createRecord', 'createFromBase64', 'upload', 'uploadBase64' => 'create',
            'delete', 'deleteRecord', 'bulkDelete'                                 => 'delete',
            'dispatch', 'dispatchOrder', 'bulkDispatch'                            => 'dispatch',
            'bulkAssignDriver'                                                     => 'assign-driver-for',
            'importFromFiles'                                                      => 'import',
            'editOrderRoute'                                                       => 'update-route-for',
            'scheduleOrder'                                                        => 'schedule',
            'cancel', 'cancelOrder', 'bulkCancel'                                  => 'cancel',
            default                                                                => $request->isMethodSafe() ? 'view' : 'update',
        };
        $permission = ($isOrder ? 'fleet-ops ' : 'fleetbase ') . $action . ($isOrder ? ' order' : ' file');
        if (Auth::cannotUnlessAdmin($permission)) {
            return response()->json(['errors' => ['This operation is not authorized.']], 403);
        }
        $id = $route->parameter('id') ?? $route->parameter($isOrder ? 'order' : 'file');
        if ($isFile && $id === null && $method === 'download') {
            $id = $request->query('file');
        }
        if ($id !== null) {
            $model = $isOrder ? new Order() : new File();
            $found = is_string($id) && $model->newQuery()->where('company_uuid', session('company'))
                ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id)
                    ->when($isOrder, fn ($q) => $q->orWhere('internal_id', $id)))->exists();
            if (!$found) {
                return response()->json(['errors' => ['Resource not found.']], 404);
            }
        }

        return $next($request);
    }
}
