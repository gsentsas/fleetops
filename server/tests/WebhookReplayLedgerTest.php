<?php

declare(strict_types=1);

use Fleetbase\FleetOps\Support\Telematics\WebhookReplayLedger;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;

/**
 * An isolated in-memory SQL contract test; NOT a real HTTP or prod DB test.
 * With $connection, the receipts table exists ONLY on that named connection
 * (the default connection stays empty), like Fleetbase's sandbox connection.
 */
function gstFleetReplayFixture(?string $connection = null): WebhookReplayLedger
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    if ($connection !== null) {
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], $connection);
    }
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    app()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    app()->instance('db.schema', $capsule->getDatabaseManager()->connection($connection)->getSchemaBuilder());
    Facade::clearResolvedInstance('db.schema');
    $migration = require __DIR__ . '/../migrations/2026_11_08_000001_create_telematic_webhook_receipts_table.php';
    $migration->up();

    return new WebhookReplayLedger($connection);
}

/** A business table on the ledger connection, standing in for the webhook's model writes. */
function gstFleetReplayEffectsTable(?string $connection = null): void
{
    Illuminate\Support\Facades\DB::connection($connection)->getSchemaBuilder()->create('gst_test_effects', function ($table): void {
        $table->string('marker', 64)->primary();
    });
}

function gstFleetReplayEffectRows(?string $connection = null): int
{
    return Illuminate\Support\Facades\DB::connection($connection)->table('gst_test_effects')->count();
}

function gstFleetReplayRows(?string $connection = null): int
{
    return Illuminate\Support\Facades\DB::connection($connection)->table(WebhookReplayLedger::TABLE)->count();
}

function gstFleetReplayExpire(array $claim, string $column = 'lease_expires_at'): void
{
    Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)
        ->where('scope_hash', $claim['scope_hash'])
        ->where('receipt_key', $claim['receipt_key'])
        ->update([$column => '2000-01-01 00:00:00']);
}

function gstFleetReplayScope(array $overrides = []): array
{
    return array_replace([
        'company_uuid'     => 'tenant-one',
        'provider'         => 'samsara',
        'integration_uuid' => 'integration-one',
        'endpoint'         => 'provider',
    ], $overrides);
}

test('ledger single-claim winner and in-progress duplicate', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $one    = $ledger->claim($scope, '{"event":1}', 'id-1');
    $two    = $ledger->claim($scope, '{"event":1}', 'id-1');
    expect($one['decision'])->toBe('ACQUIRED')
        ->and(is_string($one['token']) ? strlen($one['token']) : -1)->toBe(64)
        ->and($two['decision'])->toBe('IN_PROGRESS')
        ->and($two['token'])->toBeNull();
});

test('ledger completed claims are duplicate and cannot be completed twice', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $one    = $ledger->claim($scope, 'body', 'id-2');
    expect($ledger->complete($one['scope_hash'], $one['receipt_key'], $one['token']))->toBeTrue()
        ->and($ledger->complete($one['scope_hash'], $one['receipt_key'], $one['token']))->toBeFalse()
        ->and($ledger->claim($scope, 'body', 'id-2')['decision'])->toBe('DUPLICATE_PROCESSED');
});

test('ledger rejects one client key used with different raw body', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $ledger->claim($scope, 'body-A', 'id-3');
    expect($ledger->claim($scope, 'body-B', 'id-3')['decision'])->toBe('PAYLOAD_CONFLICT');
});

test('ledger isolates company provider integration and endpoint', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    expect($ledger->claim($scope, 'body', 'key')['decision'])->toBe('ACQUIRED');
    foreach ([
        ['company_uuid' => 'tenant-two'],
        ['provider' => 'flespi'],
        ['integration_uuid' => 'integration-two'],
        ['endpoint' => 'custom'],
    ] as $change) {
        expect($ledger->claim(gstFleetReplayScope($change), 'body', 'key')['decision'])->toBe('ACQUIRED');
    }
});

test('ledger no-header receipt is bound to raw bytes', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    expect($ledger->claim($scope, 'raw-body', null)['decision'])->toBe('ACQUIRED')
        ->and($ledger->claim($scope, 'raw-body', null)['decision'])->toBe('IN_PROGRESS')
        ->and($ledger->claim($scope, 'raw-body-2', null)['decision'])->toBe('ACQUIRED');
});

test('ledger release requires the original owner and no effects', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $claim  = $ledger->claim($scope, 'body', 'release-1');
    $wrong  = str_repeat('a', 64);
    expect($ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $wrong))->toBeFalse()
        ->and($ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $claim['token']))->toBeTrue()
        ->and($ledger->claim($scope, 'body', 'release-1')['decision'])->toBe('ACQUIRED');
});

test('ledger rejects empty idempotency key and unsafe scope before writes', function () {
    $ledger = gstFleetReplayFixture();
    expect(fn () => $ledger->claim(gstFleetReplayScope(), 'body', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ledger->claim(gstFleetReplayScope(['provider' => '']), 'body', 'key'))
            ->toThrow(InvalidArgumentException::class);
});

test('ledger does not reclaim expired in-progress lease automatically', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $one    = $ledger->claim($scope, 'body', 'stale-1');
    Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)
        ->where('scope_hash', $one['scope_hash'])
        ->where('receipt_key', $one['receipt_key'])
        ->update(['lease_expires_at' => '2000-01-01 00:00:00']);
    expect($ledger->claim($scope, 'body', 'stale-1')['decision'])->toBe('STALE_INDETERMINATE');
});

test('ledger preserves processed receipts without automatic 24h expiry', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $one    = $ledger->claim($scope, 'body', 'retention-1');
    $ledger->complete($one['scope_hash'], $one['receipt_key'], $one['token']);
    Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)
        ->where('scope_hash', $one['scope_hash'])
        ->where('receipt_key', $one['receipt_key'])
        ->update(['completed_at' => '2000-01-01 00:00:00']);
    expect($ledger->claim($scope, 'body', 'retention-1')['decision'])->toBe('DUPLICATE_PROCESSED');
});

// ---- Regressions for P0-F7D1 / Phase B (each maps to a measured defect or contract) ----

test('ledger rejects the P0-F7D1 harness endpoint before any write', function () {
    // Root cause of FAIL_INVALID_SCOPE: the R2/R3 harness used this endpoint.
    $ledger = gstFleetReplayFixture();
    expect(fn () => $ledger->claim(gstFleetReplayScope(['endpoint' => 'telemetrics:handle']), 'body', 'key'))
        ->toThrow(InvalidArgumentException::class, 'WEBHOOK_ENDPOINT_INVALID')
        ->and(gstFleetReplayRows())->toBe(0);
});

test('ledger rejects reordered, missing or extra scope keys', function () {
    $ledger    = gstFleetReplayFixture();
    $scope     = gstFleetReplayScope();
    $reordered = ['provider' => $scope['provider']] + $scope;
    $missing   = $scope;
    unset($missing['integration_uuid']);
    foreach ([$reordered, $missing, $scope + ['extra' => 'x']] as $bad) {
        expect(fn () => $ledger->claim($bad, 'body', 'key'))->toThrow(InvalidArgumentException::class, 'WEBHOOK_SCOPE_KEYS_INVALID');
    }
    expect(gstFleetReplayRows())->toBe(0);
});

test('ledger enforces the idempotency key and body size limits', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    expect(WebhookReplayLedger::isValidClientKey(str_repeat('k', 256)))->toBeTrue()
        ->and(WebhookReplayLedger::isValidClientKey(str_repeat('k', 257)))->toBeFalse()
        ->and(WebhookReplayLedger::isValidClientKey('has space'))->toBeFalse()
        ->and(WebhookReplayLedger::isValidClientKey("tab\tkey"))->toBeFalse()
        ->and(WebhookReplayLedger::isValidClientKey("cl\u{e9}"))->toBeFalse()
        ->and(fn () => $ledger->claim($scope, 'body', "a\nb"))->toThrow(InvalidArgumentException::class, 'WEBHOOK_IDEMPOTENCY_KEY_INVALID')
        ->and(fn () => $ledger->claim($scope, str_repeat('x', WebhookReplayLedger::MAX_BODY_BYTES + 1), null))
            ->toThrow(InvalidArgumentException::class, 'WEBHOOK_BODY_TOO_LARGE')
        ->and($ledger->claim($scope, str_repeat('x', WebhookReplayLedger::MAX_BODY_BYTES), null)['decision'])->toBe('ACQUIRED');
});

test('ledger fences on the exact lowercase owner token', function () {
    // MySQL utf8mb4_unicode_ci matched an upper-cased token (measured TOKEN_CASE_FENCING=FAIL).
    $ledger = gstFleetReplayFixture();
    $claim  = $ledger->claim(gstFleetReplayScope(), 'body', 'case-1');
    $upper  = strtoupper($claim['token']);
    expect(fn () => $ledger->complete($claim['scope_hash'], $claim['receipt_key'], $upper))
        ->toThrow(InvalidArgumentException::class, 'WEBHOOK_RECEIPT_TOKEN_OR_KEY_INVALID')
        ->and(fn () => $ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $upper))
            ->toThrow(InvalidArgumentException::class, 'WEBHOOK_RECEIPT_TOKEN_OR_KEY_INVALID')
        ->and(fn () => $ledger->complete(strtoupper($claim['scope_hash']), $claim['receipt_key'], $claim['token']))
            ->toThrow(InvalidArgumentException::class)
        ->and($ledger->complete($claim['scope_hash'], $claim['receipt_key'], str_repeat('b', 64)))->toBeFalse()
        ->and($ledger->complete($claim['scope_hash'], $claim['receipt_key'], $claim['token']))->toBeTrue();
});

test('ledger cannot complete a released claim', function () {
    $ledger = gstFleetReplayFixture();
    $claim  = $ledger->claim(gstFleetReplayScope(), 'body', 'released-1');
    expect($ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $claim['token']))->toBeTrue()
        ->and($ledger->complete($claim['scope_hash'], $claim['receipt_key'], $claim['token']))->toBeFalse()
        ->and(gstFleetReplayRows())->toBe(0);
});

test('ledger fails closed on an unknown stored status', function () {
    $ledger = gstFleetReplayFixture();
    $claim  = $ledger->claim(gstFleetReplayScope(), 'body', 'status-1');
    Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)->where('receipt_key', $claim['receipt_key'])->update(['status' => 'weird']);
    expect(fn () => $ledger->claim(gstFleetReplayScope(), 'body', 'status-1'))->toThrow(RuntimeException::class, 'WEBHOOK_CLAIM_UNKNOWN_STATUS');
});

test('ledger fails closed when its table is missing', function () {
    $ledger = gstFleetReplayFixture();
    Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->drop(WebhookReplayLedger::TABLE);
    expect(fn () => $ledger->claim(gstFleetReplayScope(), 'body', 'missing-1'))->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => $ledger->processAtomically(gstFleetReplayScope(), 'body', 'missing-1', fn () => 'ran'))
            ->toThrow(Illuminate\Database\QueryException::class);
});

test('ledger processAtomically runs effects once and commits them with the receipt', function () {
    $ledger = gstFleetReplayFixture();
    gstFleetReplayEffectsTable();
    $runs   = 0;
    $effect = function () use (&$runs) {
        $runs++;
        Illuminate\Support\Facades\DB::table('gst_test_effects')->insert(['marker' => 'event-1']);

        return 'stored';
    };
    $first  = $ledger->processAtomically(gstFleetReplayScope(), '{"e":1}', 'atomic-1', $effect);
    $second = $ledger->processAtomically(gstFleetReplayScope(), '{"e":1}', 'atomic-1', $effect);
    expect($first['decision'])->toBe('PROCESSED')
        ->and($first['result'])->toBe('stored')
        ->and($second['decision'])->toBe('DUPLICATE_PROCESSED')
        ->and($second['result'])->toBeNull()
        ->and($runs)->toBe(1)
        ->and(gstFleetReplayEffectRows())->toBe(1)
        ->and(Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)->value('status'))->toBe('processed');
});

test('ledger processAtomically rolls back receipt and effects together on failure', function () {
    // Stands in for a crash after a business write and before finalization.
    $ledger = gstFleetReplayFixture();
    gstFleetReplayEffectsTable();
    $fail = function () {
        Illuminate\Support\Facades\DB::table('gst_test_effects')->insert(['marker' => 'event-2']);
        throw new RuntimeException('BUSINESS_FAILURE');
    };
    expect(fn () => $ledger->processAtomically(gstFleetReplayScope(), 'body', 'atomic-2', $fail))
        ->toThrow(RuntimeException::class, 'BUSINESS_FAILURE')
        ->and(gstFleetReplayRows())->toBe(0)
        ->and(gstFleetReplayEffectRows())->toBe(0);
    $retry = $ledger->processAtomically(gstFleetReplayScope(), 'body', 'atomic-2', function () {
        Illuminate\Support\Facades\DB::table('gst_test_effects')->insert(['marker' => 'event-2']);

        return 'ok';
    });
    expect($retry['decision'])->toBe('PROCESSED')->and(gstFleetReplayEffectRows())->toBe(1);
});

test('ledger processAtomically never runs effects when it does not own the receipt', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $runs   = 0;
    $effect = function () use (&$runs) {
        $runs++;

        return 'ran';
    };
    $held   = $ledger->claim($scope, 'body-A', 'owned-1');
    expect($ledger->processAtomically($scope, 'body-A', 'owned-1', $effect)['decision'])->toBe('IN_PROGRESS')
        ->and($ledger->processAtomically($scope, 'body-B', 'owned-1', $effect)['decision'])->toBe('PAYLOAD_CONFLICT');
    gstFleetReplayExpire($held);
    expect($ledger->processAtomically($scope, 'body-A', 'owned-1', $effect)['decision'])->toBe('STALE_INDETERMINATE')
        ->and($runs)->toBe(0);
});

test('ledger processAtomically refuses to run inside a caller transaction', function () {
    $ledger = gstFleetReplayFixture();
    $runs   = 0;
    Illuminate\Support\Facades\DB::connection()->beginTransaction();
    try {
        expect(fn () => $ledger->processAtomically(gstFleetReplayScope(), 'body', 'nested-1', function () use (&$runs) { $runs++; }))
            ->toThrow(RuntimeException::class, 'WEBHOOK_PROCESS_NESTED_TRANSACTION');
    } finally {
        Illuminate\Support\Facades\DB::connection()->rollBack();
    }
    expect($runs)->toBe(0)->and(gstFleetReplayRows())->toBe(0);
});

test('ledger processAtomically validates input before opening a transaction', function () {
    $ledger = gstFleetReplayFixture();
    expect(fn () => $ledger->processAtomically(gstFleetReplayScope(['endpoint' => 'other']), 'body', 'k', fn () => null))
        ->toThrow(InvalidArgumentException::class, 'WEBHOOK_ENDPOINT_INVALID')
        ->and(Illuminate\Support\Facades\DB::connection()->transactionLevel())->toBe(0);
});

test('ledger reports stale receipts without secrets and resolves only expired ones', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $live   = $ledger->claim($scope, 'body', 'live-1');
    $staleA = $ledger->claim($scope, 'body', 'stale-a');
    $staleB = $ledger->claim($scope, 'body', 'stale-b');
    gstFleetReplayExpire($staleA);
    gstFleetReplayExpire($staleB);
    $found = $ledger->findStale();
    expect(count($found))->toBe(2)
        ->and(array_keys($found[0]))->toBe(['scope_hash', 'receipt_key', 'lease_expires_at', 'created_at'])
        ->and(json_encode($found))->not->toContain($staleA['token'])
        ->and($ledger->resolveStaleAsProcessed($live['scope_hash'], $live['receipt_key']))->toBeFalse()
        ->and($ledger->releaseStaleWithoutEffects($live['scope_hash'], $live['receipt_key']))->toBeFalse()
        ->and($ledger->resolveStaleAsProcessed($staleA['scope_hash'], $staleA['receipt_key']))->toBeTrue()
        ->and($ledger->claim($scope, 'body', 'stale-a')['decision'])->toBe('DUPLICATE_PROCESSED')
        ->and($ledger->releaseStaleWithoutEffects($staleA['scope_hash'], $staleA['receipt_key']))->toBeFalse()
        ->and($ledger->releaseStaleWithoutEffects($staleB['scope_hash'], $staleB['receipt_key']))->toBeTrue()
        ->and($ledger->claim($scope, 'body', 'stale-b')['decision'])->toBe('ACQUIRED')
        ->and($ledger->claim($scope, 'body', 'live-1')['decision'])->toBe('IN_PROGRESS');
});

test('ledger retention purge keeps a seven day floor and only deletes processed receipts', function () {
    $ledger = gstFleetReplayFixture();
    $scope  = gstFleetReplayScope();
    $old    = $ledger->claim($scope, 'body', 'old-processed');
    $ledger->complete($old['scope_hash'], $old['receipt_key'], $old['token']);
    gstFleetReplayExpire($old, 'completed_at');
    $recent = $ledger->claim($scope, 'body', 'recent-processed');
    $ledger->complete($recent['scope_hash'], $recent['receipt_key'], $recent['token']);
    $oldProcessing = $ledger->claim($scope, 'body', 'old-processing');
    gstFleetReplayExpire($oldProcessing);
    // Even an inconsistent 'processing' row carrying a completed_at is never purged.
    Illuminate\Support\Facades\DB::table(WebhookReplayLedger::TABLE)->where('receipt_key', $oldProcessing['receipt_key'])
        ->update(['created_at' => '2000-01-01 00:00:00', 'completed_at' => '2000-01-01 00:00:00']);

    $utc = new DateTimeZone('UTC');
    expect(fn () => $ledger->purgeProcessedBefore(new DateTimeImmutable('-1 day', $utc)))
        ->toThrow(InvalidArgumentException::class, 'WEBHOOK_RETENTION_BELOW_MINIMUM')
        ->and(fn () => $ledger->purgeProcessedBefore(new DateTimeImmutable('-6 days -23 hours', $utc)))
            ->toThrow(InvalidArgumentException::class, 'WEBHOOK_RETENTION_BELOW_MINIMUM')
        ->and(gstFleetReplayRows())->toBe(3)
        ->and($ledger->purgeProcessedBefore(new DateTimeImmutable('-8 days', $utc)))->toBe(1)
        ->and(gstFleetReplayRows())->toBe(2)
        ->and($ledger->claim($scope, 'body', 'recent-processed')['decision'])->toBe('DUPLICATE_PROCESSED')
        ->and($ledger->claim($scope, 'body', 'old-processing')['decision'])->toBe('STALE_INDETERMINATE')
        ->and($ledger->claim($scope, 'body', 'old-processed')['decision'])->toBe('ACQUIRED');
});

test('ledger writes on the named connection of the webhook models only', function () {
    // Fleetbase models use config('fleetbase.connection.db'), e.g. a sandbox connection.
    $ledger = gstFleetReplayFixture('gst_sandbox');
    gstFleetReplayEffectsTable('gst_sandbox');
    $result = $ledger->processAtomically(gstFleetReplayScope(), 'body', 'named-1', function () {
        Illuminate\Support\Facades\DB::connection('gst_sandbox')->table('gst_test_effects')->insert(['marker' => 'named']);

        return 'ok';
    });
    expect($result['decision'])->toBe('PROCESSED')
        ->and(gstFleetReplayRows('gst_sandbox'))->toBe(1)
        ->and(gstFleetReplayEffectRows('gst_sandbox'))->toBe(1)
        ->and(Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->hasTable(WebhookReplayLedger::TABLE))->toBeFalse()
        ->and(fn () => (new WebhookReplayLedger())->claim(gstFleetReplayScope(), 'body', 'named-1'))
            ->toThrow(Illuminate\Database\QueryException::class);
});

/** A driver whose INSERT reports an impossible row count for a single-row insert. */
final class GstFleetReplayOddCountConnection extends Illuminate\Database\SQLiteConnection
{
    public function query()
    {
        return new class($this, $this->getQueryGrammar(), $this->getPostProcessor()) extends Illuminate\Database\Query\Builder {
            public function insertOrIgnore(array $values)
            {
                return 2;
            }
        };
    }
}

test('ledger fails closed when the database silently drops the receipt insert', function () {
    // MySQL INSERT IGNORE drops rows for non-unique reasons too (CHECK,
    // conversion). SQLite's RAISE(IGNORE) trigger reproduces that: 0 rows
    // inserted and no existing row. Never a duplicate, never a success.
    $ledger = gstFleetReplayFixture();
    gstFleetReplayEffectsTable();
    Illuminate\Support\Facades\DB::connection()->statement('CREATE TRIGGER gst_drop_receipts BEFORE INSERT ON ' . WebhookReplayLedger::TABLE . ' BEGIN SELECT RAISE(IGNORE); END');
    $ran = 0;

    expect(fn () => $ledger->claim(gstFleetReplayScope(), 'body', 'dropped-1'))
        ->toThrow(RuntimeException::class, 'WEBHOOK_CLAIM_INDETERMINATE_DB_WRITE')
        ->and(fn () => $ledger->processAtomically(gstFleetReplayScope(), 'body', 'dropped-1', function () use (&$ran) {
            $ran++;
            Illuminate\Support\Facades\DB::table('gst_test_effects')->insert(['marker' => 'dropped']);
        }))->toThrow(RuntimeException::class, 'WEBHOOK_CLAIM_INDETERMINATE_DB_WRITE')
        ->and($ran)->toBe(0)
        ->and(gstFleetReplayRows())->toBe(0)
        ->and(gstFleetReplayEffectRows())->toBe(0);
});

test('ledger fails closed on an unexpected insert row count', function () {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'gst_odd_count', 'database' => ':memory:', 'prefix' => ''], 'gst_odd');
    $capsule->getDatabaseManager()->extend('gst_odd_count', fn (array $config, string $name) => new GstFleetReplayOddCountConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
    app()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    expect(fn () => (new WebhookReplayLedger('gst_odd'))->claim(gstFleetReplayScope(), 'body', 'odd-1'))
        ->toThrow(RuntimeException::class, 'WEBHOOK_CLAIM_UNEXPECTED_INSERT_RESULT');
});
