<?php

namespace Fleetbase\FleetOps\Scopes;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Apply assignment to every driver HTTP lookup, including list and BFF queries. */
class AssignedDriverOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->bound('request') || !request()->route()) {
            return;
        }
        $user = Auth::getUserFromSession(request());
        if (!$user || $user->type !== 'driver') {
            return;
        }
        $company = session('company');
        $builder->where($model->qualifyColumn('company_uuid'), $company ?: '')
            ->whereIn($model->qualifyColumn('driver_assigned_uuid'), Driver::query()
                ->select('uuid')->where('company_uuid', $company ?: '')->where('user_uuid', $user->uuid));
    }
}
