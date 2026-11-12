<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Navigator's issuer, device, API credential and pairing state must share
 * one logical DB connection for a rollback to be atomic. A DB alias
 * mismatch must fail closed rather than orphan a credential or device.
 */
final class NavigatorDatabaseGuard
{
    public static function assertAtomic(): void
    {
        $connection = DB::getDefaultConnection();
        if (!is_string($connection) || $connection === ''
            || (new User())->getConnectionName() !== $connection
            || (new ApiCredential())->getConnectionName() !== $connection) {
            throw new \RuntimeException('Navigator database connection mismatch.');
        }
    }
}
