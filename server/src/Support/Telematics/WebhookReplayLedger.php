<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Support\Telematics;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Durable, SQL-backed replay ledger for authenticated telematics webhooks.
 *
 * The caller MUST verify the request method and the authentic webhook
 * signature, and resolve an integration authorized for its company, BEFORE
 * calling claim() or processAtomically(): a receipt is only ever recorded for
 * a request already proven authentic.
 *
 * Two ways to use it:
 *
 *  - processAtomically() (what the HTTP controller uses): claim, business
 *    writes and completion run in ONE transaction on the ledger connection,
 *    which MUST be the connection the business writes use. Any exception,
 *    crash or lost connection before COMMIT rolls the receipt back together
 *    with the business writes, so a provider retry processes again safely; a
 *    COMMIT makes both durable together, so a retry is a duplicate. Database
 *    side effects therefore happen exactly once per receipt. Effects outside
 *    the database (broadcasts, queued jobs, HTTP calls) are NOT covered:
 *    defer them with DB::afterCommit() so a rolled-back attempt emits nothing.
 *
 *  - claim() / complete() / releaseWithoutEffects(): the non-transactional
 *    primitive. A crash between claim() and complete() leaves a 'processing'
 *    row whose outcome is unknown; it is reported as IN_PROGRESS, then
 *    STALE_INDETERMINATE once its lease expires, and is NEVER reclaimed
 *    automatically. Only an operator, after checking the business data, may
 *    resolve it (resolveStaleAsProcessed() / releaseStaleWithoutEffects()).
 *
 * Every database failure propagates as an exception (fail closed): the ledger
 * never reports success, nor a known duplicate, that it could not prove.
 */
final class WebhookReplayLedger
{
    public const TABLE = 'telematic_webhook_receipts';

    public const ACQUIRED            = 'ACQUIRED';
    public const PROCESSED           = 'PROCESSED';
    public const DUPLICATE_PROCESSED = 'DUPLICATE_PROCESSED';
    public const IN_PROGRESS         = 'IN_PROGRESS';
    public const STALE_INDETERMINATE = 'STALE_INDETERMINATE';
    public const PAYLOAD_CONFLICT    = 'PAYLOAD_CONFLICT';

    public const ENDPOINTS = ['provider', 'custom'];

    /** Processed receipts are kept at least this long, whatever the caller asks. */
    public const MIN_RETENTION_DAYS = 7;

    public const MAX_BODY_BYTES = 1048576;

    private const CLAIM_LEASE_SECONDS = 60;
    private const STATUS_PROCESSING   = 'processing';
    private const STATUS_PROCESSED    = 'processed';

    /**
     * @param string|null $connection Database connection name. Pass the
     *                                connection of the models the webhook
     *                                writes (e.g. $telematic->getConnectionName()).
     */
    public function __construct(private readonly ?string $connection = null)
    {
    }

    /**
     * Atomically reserve a receipt. Never reads before writing: atomicity
     * comes from the UNIQUE(scope_hash, receipt_key) primary key and a SINGLE
     * insert, so the database must be shared by every FleetOps web worker.
     *
     * @param array{company_uuid:string,provider:string,integration_uuid:string,endpoint:string} $scope
     *
     * @return array{decision:string,scope_hash:string,receipt_key:string,token:?string}
     */
    public function claim(array $scope, string $rawBody, ?string $clientKey): array
    {
        [$scopeHash, $receiptKey, $payloadHash] = $this->identity($scope, $rawBody, $clientKey);
        $token                                  = bin2hex(random_bytes(32));
        $now                                    = $this->now();
        $row                                    = [
            'scope_hash'       => $scopeHash,
            'receipt_key'      => $receiptKey,
            'payload_hash'     => $payloadHash,
            'claim_token'      => $token,
            'status'           => self::STATUS_PROCESSING,
            'lease_expires_at' => $now->modify('+' . self::CLAIM_LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            'completed_at'     => null,
            'created_at'       => $now->format('Y-m-d H:i:s'),
            'updated_at'       => $now->format('Y-m-d H:i:s'),
        ];

        $inserted = $this->db()->table(self::TABLE)->insertOrIgnore($row);
        if ($inserted === 1) {
            return $this->verdict(self::ACQUIRED, $scopeHash, $receiptKey, $token);
        }
        if ($inserted !== 0) {
            throw new \RuntimeException('WEBHOOK_CLAIM_UNEXPECTED_INSERT_RESULT');
        }

        // A LOCKING read returns the latest committed row even when the
        // caller's transaction already holds an older consistent-read
        // snapshot (MySQL REPEATABLE READ); a plain SELECT would miss a claim
        // committed by a peer after that snapshot.
        $existing = $this->db()->table(self::TABLE)
            ->where('scope_hash', $scopeHash)
            ->where('receipt_key', $receiptKey)
            ->sharedLock()
            ->first();
        if ($existing === null) {
            // MySQL INSERT IGNORE (Laravel insertOrIgnore) also drops rows for
            // non-unique reasons (CHECK, conversion...). Never read a missing
            // row as a duplicate or as a success.
            throw new \RuntimeException('WEBHOOK_CLAIM_INDETERMINATE_DB_WRITE');
        }
        $storedPayloadHash = self::column($existing, 'payload_hash');
        if ($storedPayloadHash === null || !hash_equals($storedPayloadHash, $payloadHash)) {
            return $this->verdict(self::PAYLOAD_CONFLICT, $scopeHash, $receiptKey);
        }
        $status = self::column($existing, 'status');
        if ($status === self::STATUS_PROCESSED) {
            return $this->verdict(self::DUPLICATE_PROCESSED, $scopeHash, $receiptKey);
        }
        if ($status !== self::STATUS_PROCESSING) {
            throw new \RuntimeException('WEBHOOK_CLAIM_UNKNOWN_STATUS');
        }

        // Never steal an expired lease: the previous worker may have written
        // business data and crashed before completing this receipt.
        $decision = $this->leaseExpired(self::column($existing, 'lease_expires_at'), $now) ? self::STALE_INDETERMINATE : self::IN_PROGRESS;

        return $this->verdict($decision, $scopeHash, $receiptKey);
    }

    /**
     * Claim, run $effects, complete — all in one transaction on the ledger
     * connection. $effects runs ONLY when this call acquired the receipt.
     *
     * Deadlock / lock-timeout victims are retried by Laravel, up to
     * $attempts times; $effects may therefore run again after a rollback and
     * must not perform non-transactional effects except via DB::afterCommit().
     *
     * @param array{company_uuid:string,provider:string,integration_uuid:string,endpoint:string} $scope
     *
     * @return array{decision:string,scope_hash:string,receipt_key:string,result:mixed} decision is PROCESSED,
     *                                                                                  DUPLICATE_PROCESSED, IN_PROGRESS, STALE_INDETERMINATE or PAYLOAD_CONFLICT
     */
    public function processAtomically(array $scope, string $rawBody, ?string $clientKey, callable $effects, int $attempts = 3): array
    {
        // Validate before opening a transaction; throws InvalidArgumentException.
        $this->identity($scope, $rawBody, $clientKey);

        $db = $this->db();
        if ($db->transactionLevel() > 0) {
            // A surrounding transaction would decide the receipt's fate and
            // disable deadlock retries; the handler must own its transaction.
            throw new \RuntimeException('WEBHOOK_PROCESS_NESTED_TRANSACTION');
        }

        /** @var array{decision:string,scope_hash:string,receipt_key:string,result:mixed} $outcome the callback's return value */
        $outcome = $db->transaction(function () use ($scope, $rawBody, $clientKey, $effects): array {
            $claim = $this->claim($scope, $rawBody, $clientKey);
            if ($claim['decision'] !== self::ACQUIRED) {
                return ['decision' => $claim['decision'], 'scope_hash' => $claim['scope_hash'], 'receipt_key' => $claim['receipt_key'], 'result' => null];
            }

            $result = $effects();

            if (!$this->complete($claim['scope_hash'], $claim['receipt_key'], (string) $claim['token'])) {
                // Never commit business writes without our own receipt.
                throw new \RuntimeException('WEBHOOK_CLAIM_FENCING_LOST');
            }

            return ['decision' => self::PROCESSED, 'scope_hash' => $claim['scope_hash'], 'receipt_key' => $claim['receipt_key'], 'result' => $result];
        }, max(1, $attempts));

        return $outcome;
    }

    /** Owner-fenced completion. Inside processAtomically() it shares the business transaction. */
    public function complete(string $scopeHash, string $receiptKey, string $token): bool
    {
        $this->assertHashedIdentity($scopeHash, $receiptKey, $token);
        $now = $this->now()->format('Y-m-d H:i:s');

        return $this->db()->table(self::TABLE)
            ->where('scope_hash', $scopeHash)
            ->where('receipt_key', $receiptKey)
            ->where('claim_token', $token)
            ->where('status', self::STATUS_PROCESSING)
            ->update(['status' => self::STATUS_PROCESSED, 'completed_at' => $now, 'updated_at' => $now]) === 1;
    }

    /** ONLY when the caller has proven that NO business effect was committed. */
    public function releaseWithoutEffects(string $scopeHash, string $receiptKey, string $token): bool
    {
        $this->assertHashedIdentity($scopeHash, $receiptKey, $token);

        return $this->db()->table(self::TABLE)
            ->where('scope_hash', $scopeHash)
            ->where('receipt_key', $receiptKey)
            ->where('claim_token', $token)
            ->where('status', self::STATUS_PROCESSING)
            ->delete() === 1;
    }

    /**
     * Receipts left 'processing' past their lease: outcome unknown, needs an
     * operator. Never returns tokens or payload digests.
     *
     * @return list<array{scope_hash:string,receipt_key:string,lease_expires_at:string,created_at:?string}>
     */
    public function findStale(int $limit = 100): array
    {
        $rows = $this->db()->table(self::TABLE)
            ->where('status', self::STATUS_PROCESSING)
            ->where('lease_expires_at', '<=', $this->now()->format('Y-m-d H:i:s'))
            ->orderBy('lease_expires_at')
            ->limit(max(1, min($limit, 1000)))
            ->get(['scope_hash', 'receipt_key', 'lease_expires_at', 'created_at']);

        $stale = [];
        foreach ($rows as $row) {
            $stale[] = [
                'scope_hash'       => (string) self::column($row, 'scope_hash'),
                'receipt_key'      => (string) self::column($row, 'receipt_key'),
                'lease_expires_at' => (string) self::column($row, 'lease_expires_at'),
                'created_at'       => self::column($row, 'created_at'),
            ];
        }

        return $stale;
    }

    /** Operator attests the business effects of a stale receipt ARE in the data: keep them, block replays. */
    public function resolveStaleAsProcessed(string $scopeHash, string $receiptKey): bool
    {
        $this->assertHashedIdentity($scopeHash, $receiptKey);
        $now = $this->now()->format('Y-m-d H:i:s');

        return $this->staleRow($scopeHash, $receiptKey, $now)
            ->update(['status' => self::STATUS_PROCESSED, 'completed_at' => $now, 'updated_at' => $now]) === 1;
    }

    /** Operator attests a stale receipt left NO business effect: allow the provider's next retry. */
    public function releaseStaleWithoutEffects(string $scopeHash, string $receiptKey): bool
    {
        $this->assertHashedIdentity($scopeHash, $receiptKey);

        return $this->staleRow($scopeHash, $receiptKey, $this->now()->format('Y-m-d H:i:s'))->delete() === 1;
    }

    /**
     * Retention: delete PROCESSED receipts completed before $cutoff (never
     * 'processing' ones). A provider redelivery older than the retention
     * window would be processed again, so keep it longer than any provider
     * retry window; a cutoff more recent than MIN_RETENTION_DAYS is refused.
     */
    public function purgeProcessedBefore(\DateTimeInterface $cutoff, int $limit = 1000): int
    {
        $floor = $this->now()->modify('-' . self::MIN_RETENTION_DAYS . ' days');
        $at    = (new \DateTimeImmutable('@' . $cutoff->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
        if ($at > $floor) {
            throw new \InvalidArgumentException('WEBHOOK_RETENTION_BELOW_MINIMUM');
        }
        $keys = $this->db()->table(self::TABLE)
            ->where('status', self::STATUS_PROCESSED)
            ->where('completed_at', '<', $at->format('Y-m-d H:i:s'))
            ->orderBy('completed_at')
            ->limit(max(1, min($limit, 10000)))
            ->get(['scope_hash', 'receipt_key']);

        $deleted = 0;
        foreach ($keys as $key) {
            $deleted += $this->db()->table(self::TABLE)
                ->where('scope_hash', (string) self::column($key, 'scope_hash'))
                ->where('receipt_key', (string) self::column($key, 'receipt_key'))
                ->where('status', self::STATUS_PROCESSED)
                ->delete();
        }

        return $deleted;
    }

    /** Idempotency header rule, exposed so HTTP callers can reject it as a 400 before any write. */
    public static function isValidClientKey(string $clientKey): bool
    {
        return strlen($clientKey) >= 1 && strlen($clientKey) <= 256 && preg_match('/\A[\x21-\x7e]+\z/D', $clientKey) === 1;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function leaseExpired(?string $leaseExpiresAt, \DateTimeImmutable $now): bool
    {
        // Values are written as 'Y-m-d H:i:s' UTC into DATETIME columns (no
        // session time-zone conversion), so lexical order is time order. A
        // missing lease reads as expired: reconciled, never re-processed.
        return (string) $leaseExpiresAt <= $now->format('Y-m-d H:i:s');
    }

    /** A string column of a query row (stdClass); null when absent or not a string. */
    private static function column(mixed $row, string $name): ?string
    {
        $value = is_object($row) ? (get_object_vars($row)[$name] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    private function staleRow(string $scopeHash, string $receiptKey, string $now): \Illuminate\Database\Query\Builder
    {
        return $this->db()->table(self::TABLE)
            ->where('scope_hash', $scopeHash)
            ->where('receipt_key', $receiptKey)
            ->where('status', self::STATUS_PROCESSING)
            ->where('lease_expires_at', '<=', $now);
    }

    /**
     * @param array<string,mixed> $scope
     *
     * @return array{string,string,string}
     */
    private function identity(array $scope, string $rawBody, ?string $clientKey): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('WEBHOOK_BODY_TOO_LARGE');
        }
        $expected = ['company_uuid', 'provider', 'integration_uuid', 'endpoint'];
        if (array_keys($scope) !== $expected) {
            throw new \InvalidArgumentException('WEBHOOK_SCOPE_KEYS_INVALID');
        }
        foreach ($scope as $field => $value) {
            if (!is_string($value) || strlen($value) < 1 || strlen($value) > 128
                || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $value)) {
                throw new \InvalidArgumentException('WEBHOOK_SCOPE_VALUE_INVALID_' . $field);
            }
        }
        if (!in_array($scope['endpoint'], self::ENDPOINTS, true)) {
            throw new \InvalidArgumentException('WEBHOOK_ENDPOINT_INVALID');
        }
        if ($clientKey !== null && !self::isValidClientKey($clientKey)) {
            throw new \InvalidArgumentException('WEBHOOK_IDEMPOTENCY_KEY_INVALID');
        }
        $payloadHash = hash('sha256', $rawBody);
        $scopeHash   = hash('sha256', json_encode(array_values($scope), JSON_THROW_ON_ERROR));
        $receiptKey  = hash('sha256', $clientKey === null ? 'body:' . $payloadHash : 'key:' . $clientKey);

        return [$scopeHash, $receiptKey, $payloadHash];
    }

    private function assertHashedIdentity(string ...$values): void
    {
        foreach ($values as $value) {
            // Canonical lowercase hex only: the ledger never produces anything
            // else, and SQL collations must not decide what "equal" means.
            if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                throw new \InvalidArgumentException('WEBHOOK_RECEIPT_TOKEN_OR_KEY_INVALID');
            }
        }
    }

    /** @return array{decision:string,scope_hash:string,receipt_key:string,token:?string} */
    private function verdict(string $decision, string $scopeHash, string $receiptKey, ?string $token = null): array
    {
        return [
            'decision'    => $decision,
            'scope_hash'  => $scopeHash,
            'receipt_key' => $receiptKey,
            'token'       => $token,
        ];
    }
}
