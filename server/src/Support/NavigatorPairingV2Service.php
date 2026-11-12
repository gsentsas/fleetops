<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\User;
use Fleetbase\Support\NavigatorCredentialScope;
use Illuminate\Support\Facades\DB;

/**
 * S98-R71: pending Navigator pairing; the scanned QR never grants a credential.
 * All actions must be gated by the disabled-by-default v2 feature flag.
 * Trust is the conjunction of a P-256 signature and exact issuer approval.
 */
class NavigatorPairingV2Service
{
    private const TABLE = 'fleetops_navigator_pending_pairings';
    private const TTL_SECONDS = 300;
    private const CHALLENGE_SECONDS = 120;

    public static function validatedPublicKey(string $pem): ?string
    {
        if (strlen($pem) > 2048 || strlen($pem) < 100) {
            return null;
        }
        $public = @openssl_pkey_get_public($pem);
        if (!$public) {
            return null;
        }
        $details = openssl_pkey_get_details($public);
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }
        return $details['key'];
    }

    /** Called ONLY inside NavigatorLinkTokenService::redeemWith transaction. */
    public function begin(User $issuer, string $pem): array
    {
        $key = self::validatedPublicKey($pem);
        if ($key === null || $issuer->type !== 'admin' || $issuer->status !== 'active'
            || !$issuer->company || $issuer->company->uuid !== $issuer->company_uuid) {
            throw new \InvalidArgumentException('Invalid Navigator pairing request.');
        }

        $session = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $expires = time() + self::TTL_SECONDS;
        $proofExpires = time() + self::CHALLENGE_SECONDS;
        $fingerprint = hash('sha256', $key);

        DB::table(self::TABLE)->insert([
            'session_id' => $session,
            'company_uuid' => $issuer->company_uuid,
            'issuer_user_uuid' => $issuer->uuid,
            'public_key_pem' => $key,
            'key_fingerprint' => $fingerprint,
            'proof_nonce' => $nonce,
            'proof_expires_unix' => $proofExpires,
            'expires_unix' => $expires,
            'verified_at' => null,
            'approved_at' => null,
            'claim_nonce' => null,
            'claim_expires_unix' => null,
            'claimed_at' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // Not a credential: the session ID and challenge only identify a pending pairing.
        return [
            'pairingSessionId' => $session,
            'companyUuid' => $issuer->company_uuid,
            'publicKeyFingerprint' => $fingerprint,
            'challenge' => $nonce,
            'challengeExpiresUnix' => $proofExpires,
            'expiresUnix' => $expires,
        ];
    }

    public static function message(string $phase, object $row): string
    {
        if (!in_array($phase, ['proof', 'claim'], true)) {
            throw new \InvalidArgumentException('Invalid Navigator challenge phase.');
        }

        $nonce = $phase === 'proof' ? $row->proof_nonce : $row->claim_nonce;
        $expiry = $phase === 'proof' ? $row->proof_expires_unix : $row->claim_expires_unix;

        return implode("\n", [
            'fleetbase.navigator.pairing.v2/' . $phase,
            $row->session_id,
            $row->company_uuid,
            $row->key_fingerprint,
            $nonce,
            (string) $expiry,
        ]);
    }

    private static function signatureMatches(object $row, string $phase, string $signatureBase64): bool
    {
        if (strlen($signatureBase64) > 1024 || strlen($signatureBase64) < 8) {
            return false;
        }
        $signature = base64_decode($signatureBase64, true);
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        return openssl_verify(self::message($phase, $row), $signature, $row->public_key_pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function issuerStillActive(object $row): ?User
    {
        $issuer = User::query()->where('uuid', $row->issuer_user_uuid)
            ->where('company_uuid', $row->company_uuid)
            ->where('type', 'admin')->where('status', 'active')
            ->lockForUpdate()->first();
        return $issuer && $issuer->company && $issuer->company->uuid === $row->company_uuid
            ? $issuer
            : null;
    }

    public function prove(string $sessionId, string $signatureBase64): bool
    {
        return DB::transaction(function () use ($sessionId, $signatureBase64): bool {
            $row = DB::table(self::TABLE)->where('session_id', $sessionId)->lockForUpdate()->first();
            if (!$row || $row->verified_at !== null || $row->claimed_at !== null
                || (int) $row->expires_unix <= time() || (int) $row->proof_expires_unix <= time()
                || !self::signatureMatches($row, 'proof', $signatureBase64)
                || !self::issuerStillActive($row)) {
                return false;
            }
            DB::table(self::TABLE)->where('session_id', $sessionId)->update([
                'verified_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
            return true;
        }, 3);
    }

    /**
     * Protected console-only discovery: scoped to the exact issuing
     * administrator AND company. No QR secret, private key, challenge
     * nonce, credential, or claim secret is included.
     */
    public function pendingForIssuer(User $actor): array
    {
        if ($actor->type !== 'admin' || $actor->status !== 'active'
            || !$actor->company || $actor->company->uuid !== $actor->company_uuid) {
            return [];
        }

        $issuer = User::query()->where('uuid', $actor->uuid)
            ->where('company_uuid', $actor->company_uuid)
            ->where('type', 'admin')->where('status', 'active')->first();
        if (!$issuer) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('company_uuid', $issuer->company_uuid)
            ->where('issuer_user_uuid', $issuer->uuid)
            ->where('expires_unix', '>', time())
            ->whereNotNull('verified_at')
            ->whereNull('approved_at')
            ->whereNull('claimed_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['session_id', 'key_fingerprint', 'expires_unix', 'verified_at'])
            ->map(fn ($row) => [
                'pairingSessionId' => $row->session_id,
                'publicKeyFingerprint' => $row->key_fingerprint,
                'deviceConfirmationCode' => substr($row->key_fingerprint, 0, 12),
                'expiresUnix' => (int) $row->expires_unix,
                'verified' => true,
            ])
            ->all();
    }

    public function approve(User $actor, string $sessionId, string $deviceConfirmationCode): bool
    {
        if (!preg_match('/^[a-f0-9]{12}$/D', $deviceConfirmationCode)) {
            return false;
        }
        return DB::transaction(function () use ($actor, $sessionId, $deviceConfirmationCode): bool {
            $row = DB::table(self::TABLE)->where('session_id', $sessionId)->lockForUpdate()->first();
            if (!$row || !hash_equals(substr($row->key_fingerprint, 0, 12), $deviceConfirmationCode)
                || !$row->verified_at || $row->approved_at !== null
                || $row->claimed_at !== null || (int) $row->expires_unix <= time()
                || $actor->uuid !== $row->issuer_user_uuid || $actor->company_uuid !== $row->company_uuid
                || $actor->type !== 'admin' || $actor->status !== 'active'
                || !self::issuerStillActive($row)) {
                return false;
            }
            $expiry = min(time() + self::CHALLENGE_SECONDS, (int) $row->expires_unix);
            DB::table(self::TABLE)->where('session_id', $sessionId)->update([
                'approved_at' => now()->toDateTimeString(),
                'claim_nonce' => bin2hex(random_bytes(32)),
                'claim_expires_unix' => $expiry,
                'updated_at' => now()->toDateTimeString(),
            ]);
            return true;
        }, 3);
    }

    /** Public status never returns a credential or creates authorization. */
    public function status(string $sessionId): ?array
    {
        $row = DB::table(self::TABLE)->where('session_id', $sessionId)->first();
        if (!$row || (int) $row->expires_unix <= time() || $row->claimed_at !== null) {
            return null;
        }
        return [
            'verified' => $row->verified_at !== null,
            'approved' => $row->approved_at !== null,
            'claimChallenge' => $row->approved_at ? $row->claim_nonce : null,
            'claimExpiresUnix' => $row->approved_at ? (int) $row->claim_expires_unix : null,
        ];
    }

    /**
     * Final credential exists only after BOTH cryptographic proofs and
     * explicit approval of the exact issuing administrator.
     * Nested NavigatorCredentialService transaction and this claimed_at
     * transition commit or roll back together.
     */
    public function claim(string $sessionId, string $signatureBase64): ?ApiCredential
    {
        return DB::transaction(function () use ($sessionId, $signatureBase64): ?ApiCredential {
            $row = DB::table(self::TABLE)->where('session_id', $sessionId)->lockForUpdate()->first();
            if (!$row || !$row->verified_at || !$row->approved_at || !$row->claim_nonce
                || $row->claimed_at !== null || (int) $row->expires_unix <= time()
                || (int) $row->claim_expires_unix <= time()
                || !self::signatureMatches($row, 'claim', $signatureBase64)) {
                return null;
            }
            $issuer = self::issuerStillActive($row);
            if (!$issuer) {
                return null;
            }

            NavigatorDatabaseGuard::assertAtomic();
            $credential = app(NavigatorCredentialService::class)->issue($issuer);
            if (!$credential instanceof ApiCredential || !NavigatorCredentialScope::valid($credential)
                || !is_string($credential->key) || $credential->key === '') {
                throw new \RuntimeException('Navigator credential issuance failed.');
            }

            DB::table(self::TABLE)->where('session_id', $sessionId)->update([
                'claimed_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return $credential;
        }, 3);
    }
}
