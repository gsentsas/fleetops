<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * S86 foundation: atomic single-use Navigator provisioning links.
 *
 * The public Navigator controller redeems tokens through this service.
 * Production enablement remains blocked pending disposable multi-tenant
 * runtime tests, migration replay and exact-pinned release CI.
 * Only token hashes are stored; raw tokens must never be logged.
 */
class NavigatorLinkTokenService
{
    private const TABLE = 'fleetops_navigator_link_tokens';

    public function issue(User $issuer): string
    {
        if ($issuer->type !== 'admin' || $issuer->status !== 'active'
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
            'expires_at'      => now()->addMinutes(5)->toDateTimeString(),
            'consumed_at'     => null,
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);

        return $token;
    }

    public function redeem(string $token): ?User
    {
        return $this->redeemWith($token, static fn (User $issuer): User => $issuer);
    }

    /** Issuance and consumption commit together. Failed issuance leaves the link retryable. */
    public function redeemWith(string $token, \Closure $issue): mixed
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token) || strtolower($token) !== $token) {
            return null;
        }

        return DB::transaction(function () use ($token, $issue) {
            $row = DB::table(self::TABLE)
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (!$row
                || $row->consumed_at !== null
                || \Illuminate\Support\Carbon::parse($row->expires_at)->lte(now())) {
                return null;
            }

            $issuer = User::query()
                ->where('uuid', $row->issuer_user_uuid)
                ->where('company_uuid', $row->company_uuid)
                ->where('type', 'admin')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (!$issuer || !$issuer->company || $issuer->company->uuid !== $row->company_uuid) {
                return null;
            }

            $result = $issue($issuer);
            if ($result === null) {
                throw new \RuntimeException('Navigator issuance did not produce a result.');
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

            if ($updated !== 1) {
                throw new \RuntimeException('Navigator token consumption failed.');
            }

            return $result;
        }, 3);
    }
}
