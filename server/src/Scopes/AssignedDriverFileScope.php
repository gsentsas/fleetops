<?php

namespace Fleetbase\FleetOps\Scopes;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Restrict file API reads to proofs/attachments of currently assigned orders. */
class AssignedDriverFileScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->bound('request') || !request()->route()) return;
        $controller = request()->route()->getControllerClass();
        if (!in_array($controller, [
            \Fleetbase\Http\Controllers\Api\v1\FileController::class,
            \Fleetbase\Http\Controllers\Internal\v1\FileController::class,
        ], true)) return;
        $user = Auth::getUserFromSession(request());
        if (!$user || $user->type !== 'driver') return;
        $company = session('company') ?: '';
        $fileUuid = $model->qualifyColumn('uuid');
        $builder->where($model->qualifyColumn('company_uuid'), $company)
            ->where(function ($files) use ($model, $user, $company, $fileUuid) {
                $files->whereExists(function ($proofs) use ($company, $user, $fileUuid) {
                    $proofs->selectRaw('1')->from('proofs as delivery_proofs')
                        ->join('orders as delivery_orders', 'delivery_orders.uuid', '=', 'delivery_proofs.order_uuid')
                        ->join('drivers as delivery_drivers', 'delivery_drivers.uuid', '=', 'delivery_orders.driver_assigned_uuid')
                        ->whereColumn('delivery_proofs.file_uuid', $fileUuid)
                        ->where('delivery_proofs.company_uuid', $company)->whereNull('delivery_proofs.deleted_at')
                        ->where('delivery_orders.company_uuid', $company)->whereNull('delivery_orders.deleted_at')
                        ->where('delivery_drivers.company_uuid', $company)->where('delivery_drivers.user_uuid', $user->uuid)
                        ->whereNull('delivery_drivers.deleted_at');
                })->orWhere(function ($attachments) use ($model, $company, $user) {
                    $types = array_unique([Order::class, (new Order())->getMorphClass(), Utils::getModelClassName(new Order())]);
                    $attachments->whereIn($model->qualifyColumn('subject_type'), $types)
                        ->whereExists(function ($orders) use ($model, $company, $user) {
                            $orders->selectRaw('1')->from('orders as attachment_orders')
                                ->join('drivers as attachment_drivers', 'attachment_drivers.uuid', '=', 'attachment_orders.driver_assigned_uuid')
                                ->whereColumn('attachment_orders.uuid', $model->qualifyColumn('subject_uuid'))
                                ->where('attachment_orders.company_uuid', $company)->whereNull('attachment_orders.deleted_at')
                                ->where('attachment_drivers.company_uuid', $company)->where('attachment_drivers.user_uuid', $user->uuid)
                                ->whereNull('attachment_drivers.deleted_at');
                        });
                })->orWhere(function ($own) use ($model, $user, $fileUuid) {
                    // An unlinked upload is readable by its uploader, but reassignment
                    // of a proof's order must revoke access even for that uploader.
                    $own->where($model->qualifyColumn('uploader_uuid'), $user->uuid)
                        ->whereNull($model->qualifyColumn('subject_uuid'))
                        ->whereNotExists(function ($proofs) use ($fileUuid) {
                            $proofs->selectRaw('1')->from('proofs as linked_proofs')->whereColumn('linked_proofs.file_uuid', $fileUuid);
                        });
                });
            });
    }
}
