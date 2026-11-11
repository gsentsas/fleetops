<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\User;
use Fleetbase\Support\NavigatorCredentialScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NavigatorCredentialService
{
    public function issue(User $issuer): ApiCredential
    {
        return DB::transaction(function () use ($issuer): ApiCredential {
            $issuer = User::query()->where('uuid', $issuer->uuid)->lockForUpdate()->first();
            if (!$issuer || $issuer->type !== 'admin' || $issuer->status !== 'active' || !$issuer->company) {
                throw new \RuntimeException('Navigator issuer is not authorized.');
            }
            // Technical identity: no human password, membership or administrator role.
            $device = new User();
            $device->forceFill([
                'uuid' => (string) Str::uuid(),
                'company_uuid' => $issuer->company_uuid,
                'type' => 'navigator-device',
                'name' => 'Navigator device',
                'status' => 'active',
            ])->saveQuietly();

            $credential = new ApiCredential();
            $credential->forceFill([
                'user_uuid' => $device->uuid,
                'company_uuid' => $issuer->company_uuid,
                'issuer_user_uuid' => $issuer->uuid,
                'purpose' => 'navigator',
                'name' => 'NavigatorPairing-' . bin2hex(random_bytes(16)),
                'expires_at' => now()->addDays(7),
            ])->save();
            if (!NavigatorCredentialScope::valid($credential) || !is_string($credential->key) || $credential->key === '') {
                throw new \RuntimeException('Navigator credential issuance failed.');
            }
            DB::afterCommit(fn () => Log::info('Navigator credential issued', [
                'credential_uuid' => $credential->uuid, 'company_uuid' => $credential->company_uuid,
                'issuer_uuid' => $issuer->uuid, 'expires_at' => $credential->expires_at->toIso8601String(),
            ]));

            return $credential;
        });
    }

    public function revoke(User $actor, string $credentialUuid): void
    {
        DB::transaction(function () use ($actor, $credentialUuid): void {
            $actor = User::query()->where('uuid', $actor->uuid)->lockForUpdate()->first();
            if (!$actor || $actor->type !== 'admin' || $actor->status !== 'active') {
                throw new \RuntimeException('Navigator revocation is not authorized.');
            }
            $credential = ApiCredential::withoutGlobalScopes()->where('uuid', $credentialUuid)->lockForUpdate()->first();
            if (!$credential || !NavigatorCredentialScope::isNavigator($credential) || $credential->company_uuid !== $actor->company_uuid) {
                throw new \RuntimeException('Navigator credential not found.');
            }
            $credential->delete();
            DB::table('personal_access_tokens')->where('navigator_credential_uuid', $credential->uuid)->delete();
            DB::afterCommit(fn () => Log::info('Navigator credential revoked', ['credential_uuid' => $credential->uuid, 'company_uuid' => $actor->company_uuid]));
        });
    }
}
