<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S86 foundation: atomic single-use Navigator provisioning links.
 *
 * The legacy Navigator controller is NOT wired to this service yet. Do not
 * enable the public endpoint before explicit contract and integration tests.
 * Only token hashes are stored; raw tokens must never be logged.
 */
final class NavigatorLinkTokenService
{
    private const TABLE = 'fleetops_navigator_link_tokens';

    public function issue(User $issuer): string
    {
        if ($issuer->type !== 'admin'
            || !is_string($issuer->uuid) || $issuer->uuid === ''
            || !is_string($issuer->company_uuid) || $issuer->company_uuid === ''
            || !$issuer->company || $issuer->company->uuid !== $issuer->company_uuid) {
            throw new \RuntimeException('Navigator link issuer is not an authorized company administrator.');
        }

        $token = bin2hex(random_bytes(32));

        DB::table(self::TABLE)->insert([
            'token_hash'       => hash('sha256', $token),
            'company_uuid'     => $issuer->company_uuid,
            'issuer_user_uuid' => $issuer->uuid,
            'expires_at'      => now()->addMinutes(30)->toDateTimeString(),
            'consumed_at'     => null,
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);

        return $token;
    }

    public function redeem(string $token): ?User
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token) || strtolower($token) !== $token) {
            return null;
        }

        return DB::transaction(function () use ($token): ?User {
            $row = DB::table(self::TABLE)
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (!$row
                || $row->consumed_at !== null
                || \Illuminate\Support\Carbon::parse($row->expires_at)->isPast()) {
                return null;
            }

            $issuer = User::query()
                ->where('uuid', $row->issuer_user_uuid)
                ->where('company_uuid', $row->company_uuid)
                ->where('type', 'admin')
                ->first();

            if (!$issuer || !$issuer->company || $issuer->company->uuid !== $row->company_uuid) {
                return null;
            }

            // The row is locked during the check and the transition. With a
            // transactional SQL database, concurrent redeemers cannot both
            // consume this one-time token.
            $updated = DB::table(self::TABLE)
                ->where('id', $row->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);

            return $updated === 1 ? $issuer : null;
        }, 3);
    }
}
