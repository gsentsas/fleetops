<?php

namespace Fleetbase\FleetOps\Http\Middleware;

use Fleetbase\Models\ApiCredential;
use Illuminate\Support\Facades\DB;

/** Fail closed for paired driver sessions; bootstrap is already constrained by core. */
class NavigatorDriverScope
{
    public function handle($request, \Closure $next)
    {
        if (!$request->attributes->get('navigator_credential') instanceof ApiCredential
            || $request->user()?->type !== 'driver') {
            return $next($request);
        }
        $driver = DB::table('drivers')->where('user_uuid', $request->user()->uuid)
            ->where('company_uuid', $request->user()->company_uuid)->whereNull('deleted_at')->first();
        $deny = fn () => response()->error('This resource is not authorized for the Navigator driver.', 403);
        if (!$driver) {
            return $deny();
        }
        $action = $request->route()?->getActionName();
        $prefix = 'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\';
        $driverMethods = ['find', 'track', 'toggleOnline', 'registerDevice', 'currentOrganization', 'changePassword'];
        foreach ($driverMethods as $method) {
            if ($action === $prefix . 'DriverController@' . $method) {
                $id = $request->route('id');
                return $id === null || in_array($id, [$driver->uuid, $driver->public_id], true) ? $next($request) : $deny();
            }
        }
        if ($action === $prefix . 'OrderController@query' && $request->isMethod('GET')) {
            $request->attributes->set('navigator_driver_uuid', $driver->uuid);
            return $next($request);
        }
        $orderMethods = ['find', 'startOrder', 'updateActivity', 'completeOrder', 'getNextActivity', 'orderComments'];
        foreach ($orderMethods as $method) {
            if ($action === $prefix . 'OrderController@' . $method) {
                $owned = DB::table('orders')->where('company_uuid', $driver->company_uuid)
                    ->where('driver_assigned_uuid', $driver->uuid)->whereNull('deleted_at')
                    ->where('public_id', $request->route('id'))->exists();
                return $owned ? $next($request) : $deny();
            }
        }

        return $deny();
    }
}
