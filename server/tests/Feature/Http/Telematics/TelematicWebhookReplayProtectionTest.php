<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Http\Controllers\TelematicPositionWebhookController;
use Fleetbase\FleetOps\Http\Controllers\TelematicWebhookController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Fleetbase\FleetOps\Support\Telematics\WebhookReplayLedger;
use Fleetbase\Support\IdempotencyManager;
use Fleetbase\Tests\Support\WebhookReceiptDatabase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-level replay protection of the standard provider webhook and custom
 * ingest endpoints: the durable, tenant-scoped receipt shares ONE transaction
 * with real business writes (an SQLite table), so every assertion on
 * gst_replay_business is an assertion on committed data.
 *
 * Multi-process concurrency is proven against SQLite and MySQL by
 * scripts/webhook-ledger-race.php; this file proves the controller wiring.
 */
if (!function_exists('Fleetbase\FleetOps\Support\Telematics\broadcast')) {
    // Same stub and global as TelematicServiceTelemetryTest, so both files can share a process.
    eval('namespace Fleetbase\FleetOps\Support\Telematics; function broadcast($event) { $GLOBALS["fleetops_telematic_service_broadcasts"][] = $event; return $event; }');
}

const GST_REPLAY_SIGNATURE       = 'sig-SECRET-7f3a9c';
const GST_REPLAY_PROVIDER_SECRET = 'provider-SECRET-b41d';
const GST_REPLAY_INGEST_SECRET   = 'ingest-SECRET-e09b';

class GstReplayProvider implements TelematicProviderInterface
{
    public bool $signatureValid = true;
    public int $processed       = 0;

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true, 'message' => 'ok', 'metadata' => []];
    }

    public function fetchDevices(array $options = []): array
    {
        return ['devices' => [], 'next_cursor' => null, 'has_more' => false];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return ['external_id' => $externalId];
    }

    public function normalizeDevice(array $payload): array
    {
        return $payload;
    }

    public function normalizeEvent(array $payload): array
    {
        return $payload;
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        return $this->signatureValid && hash_equals(GST_REPLAY_SIGNATURE, $signature) && ($credentials['secret'] ?? null) === GST_REPLAY_PROVIDER_SECRET;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        $this->processed++;

        return ['events' => $payload['events'] ?? []];
    }

    public function getCredentialSchema(): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsDiscovery(): bool
    {
        return false;
    }

    public function getRateLimits(): array
    {
        return [];
    }
}

class GstReplayTelemetryProvider extends GstReplayProvider implements TelemetryProviderInterface
{
    public function telemetryOptions(): array
    {
        return [];
    }

    public function telemetryUnits(array $payload): array
    {
        return [];
    }

    public function normalizeTelemetrySnapshot(array $payload): array
    {
        return ['device' => [], 'event' => []];
    }
}

class GstReplayRegistry extends TelematicProviderRegistry
{
    public ?TelematicProviderInterface $override = null;

    public function __construct(public GstReplayProvider $provider)
    {
    }

    public function resolve(string $key): TelematicProviderInterface
    {
        return $this->override ?? $this->provider;
    }
}

class GstReplayService extends TelematicService
{
    public ?Telematic $telematic = null;
    public bool $failAfterWrite  = false;
    public bool $loseReceipt     = false;
    public int $calls            = 0;
    public array $published      = [];

    public function __construct(private SQLiteConnection $db)
    {
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        return $this->telematic;
    }

    public function getCredentials(Telematic $telematic): array
    {
        return ['secret' => GST_REPLAY_PROVIDER_SECRET, 'webhook_secret' => GST_REPLAY_INGEST_SECRET];
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        $this->calls++;
        // A real business write, inside the replay ledger's transaction.
        $this->db->table('gst_replay_business')->insert(['integration_uuid' => $telematic->uuid, 'marker' => $eventData['marker'] ?? null]);
        // A non-database effect, deferred exactly like the production broadcasts.
        $this->afterTelemetryCommit($telematic, function () use ($eventData) {
            $this->published[] = $eventData['marker'] ?? null;
        });

        if ($this->loseReceipt) {
            // Simulates losing the claim between the business write and finalization.
            $this->db->table(WebhookReplayLedger::TABLE)->update(['claim_token' => str_repeat('0', 64)]);
        }
        if ($this->failAfterWrite) {
            throw new RuntimeException('business failure after the write');
        }

        return new DeviceEvent();
    }

    public function defer(EloquentModel $subject, Closure $effect): void
    {
        $this->afterTelemetryCommit($subject, $effect);
    }

    public function broadcastFor(object $event, EloquentModel $subject): void
    {
        $this->broadcastTelemetry($event, false, $subject);
    }
}

class GstReplayIdempotency extends IdempotencyManager
{
    public array $consulted = [];

    public function __construct()
    {
    }

    public function isDuplicate(string $key): bool
    {
        $this->consulted[] = $key;

        return true;
    }

    public function markProcessed(string $key): void
    {
        $this->consulted[] = $key;
    }
}

class GstReplayLogRecorder
{
    public array $records = [];

    public function channel($name = null): self
    {
        return $this;
    }

    public function __call($level, $arguments)
    {
        $this->records[] = ['level' => $level, 'message' => $arguments[0] ?? null, 'context' => $arguments[1] ?? []];

        return null;
    }

    public function messages(?string $level = null): array
    {
        return array_values(array_map(fn ($r) => $r['message'], array_filter($this->records, fn ($r) => $level === null || $r['level'] === $level)));
    }
}

class GstReplayPositionControllerFake extends TelematicPositionWebhookController
{
    public array $calls = [];

    public function handle(Request $request, Inbox $inbox, string $providerKey): JsonResponse
    {
        $this->calls[] = [$providerKey, $request->headers->has('X-Webhook-Signature')];

        return response()->json(['status' => 'accepted'], 202);
    }
}

/** @return array{0:TelematicWebhookController,1:GstReplayProvider,2:GstReplayService,3:SQLiteConnection,4:GstReplayLogRecorder,5:GstReplayIdempotency,6:GstReplayRegistry} */
function gstReplayFixture(): array
{
    $db = WebhookReceiptDatabase::boot();
    $db->getSchemaBuilder()->create('gst_replay_business', function ($table) {
        $table->increments('id');
        $table->string('integration_uuid');
        $table->string('marker')->nullable();
    });
    $db->getSchemaBuilder()->create('telematics', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'meta'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    session(['company' => 'company-1']);

    $log = new GstReplayLogRecorder();
    app()->instance('log', $log);
    Log::clearResolvedInstance('log');
    $GLOBALS['fleetops_telematic_service_broadcasts'] = [];

    $provider           = new GstReplayProvider();
    $registry           = new GstReplayRegistry($provider);
    $service            = new GstReplayService($db);
    $idempotency        = new GstReplayIdempotency();
    $service->telematic = gstReplayTelematic();

    return [new TelematicWebhookController($registry, $service, $idempotency), $provider, $service, $db, $log, $idempotency, $registry];
}

function gstReplayTelematic(string $company = 'company-1', string $uuid = 'integration-1', string $provider = 'samsara'): Telematic
{
    return (new Telematic())->forceFill(array_filter(['uuid' => $uuid, 'provider' => $provider, 'company_uuid' => $company], fn ($v) => $v !== ''));
}

function gstReplayBody(string $marker = 'm1', array $extra = []): string
{
    return json_encode(['events' => [['type' => 'location', 'marker' => $marker]]] + $extra, JSON_THROW_ON_ERROR);
}

function gstReplayRequest(string $body, ?string $key = null, ?string $signature = GST_REPLAY_SIGNATURE): Request
{
    $request = Request::create('/int/v1/fleet-ops/webhooks/telematics/samsara', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    if ($signature !== null) {
        $request->headers->set('X-Webhook-Signature', $signature);
    }
    if ($key !== null) {
        $request->headers->set('X-Idempotency-Key', $key);
    }

    return $request;
}

function gstReplayIngestRequest(string $body, ?string $key = null): Request
{
    return gstReplayRequest($body, $key, hash_hmac('sha256', $body, GST_REPLAY_INGEST_SECRET));
}

function gstReplayBusiness(SQLiteConnection $db): array
{
    return $db->table('gst_replay_business')->orderBy('id')->get()->map(fn ($r) => $r->integration_uuid . ':' . $r->marker)->all();
}

function gstReplayScope(Telematic $telematic, string $endpoint = 'provider'): array
{
    return ['company_uuid' => $telematic->company_uuid, 'provider' => $telematic->provider, 'integration_uuid' => $telematic->uuid, 'endpoint' => $endpoint];
}

test('an authenticated replay is answered as a duplicate and its writes and effects happen once', function () {
    [$controller, $provider, $service, $db, , $idempotency] = gstReplayFixture();

    $first  = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $replay = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    expect($first->getStatusCode())->toBe(200)
        ->and($first->getData(true))->toBe(['status' => 'processed'])
        ->and($replay->getStatusCode())->toBe(200)
        ->and($replay->getData(true))->toBe(['status' => 'duplicate'])
        ->and($provider->processed)->toBe(1)
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1'])
        ->and($service->published)->toBe(['m1'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed'])
        // The global cache manager (cross-tenant key) is never consulted.
        ->and($idempotency->consulted)->toBe([]);
});

test('without an idempotency key the receipt is bound to the raw body', function () {
    [$controller, , , $db] = gstReplayFixture();

    $responses = [
        $controller->handle(gstReplayRequest(gstReplayBody('a')), 'samsara'),
        $controller->handle(gstReplayRequest(gstReplayBody('a')), 'samsara'),
        $controller->handle(gstReplayRequest(gstReplayBody('b')), 'samsara'),
    ];

    expect(array_map(fn ($r) => $r->getData(true)['status'], $responses))->toBe(['processed', 'duplicate', 'processed'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:a', 'integration-1:b'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed', 'processed']);
});

test('the same idempotency key and body are isolated per tenant and per integration', function () {
    [$controller, , $service, $db] = gstReplayFixture();
    $statuses                      = [];
    foreach ([['company-1', 'integration-1'], ['company-2', 'integration-2'], ['company-1', 'integration-3']] as [$company, $integration]) {
        $service->telematic = gstReplayTelematic($company, $integration);
        $statuses[]         = $controller->handle(gstReplayRequest(gstReplayBody(), 'shared-key'), 'samsara')->getData(true)['status'];
    }

    expect($statuses)->toBe(['processed', 'processed', 'processed'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1', 'integration-2:m1', 'integration-3:m1'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed', 'processed', 'processed']);
});

test('provider webhooks and custom ingest of one integration are isolated by endpoint', function () {
    [$controller, , , $db] = gstReplayFixture();
    $db->table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_one', 'company_uuid' => 'company-1', 'provider' => 'samsara']);

    $provider = $controller->handle(gstReplayRequest(gstReplayBody(), 'shared-key'), 'samsara');
    $ingest   = $controller->ingest(gstReplayIngestRequest(gstReplayBody(), 'shared-key'), 'integration-1');
    $replay   = $controller->ingest(gstReplayIngestRequest(gstReplayBody(), 'shared-key'), 'integration-1');

    expect($provider->getData(true))->toBe(['status' => 'processed'])
        ->and($ingest->getData(true))->toBe(['status' => 'ingested'])
        ->and($replay->getData(true))->toBe(['status' => 'duplicate'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1', 'integration-1:m1'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed', 'processed']);
});

test('a reused idempotency key with a different payload is rejected without effects', function () {
    [$controller, $provider, $service, $db] = gstReplayFixture();

    $controller->handle(gstReplayRequest(gstReplayBody('original'), 'key-1'), 'samsara');
    $conflict = $controller->handle(gstReplayRequest(gstReplayBody('tampered'), 'key-1'), 'samsara');

    expect($conflict->getStatusCode())->toBe(409)
        ->and($conflict->getData(true))->toBe(['error' => 'Idempotency key already used with a different payload'])
        ->and($provider->processed)->toBe(1)
        ->and(gstReplayBusiness($db))->toBe(['integration-1:original'])
        ->and($service->published)->toBe(['original'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed']);
});

test('malformed idempotency keys are rejected before any reservation or business write', function (string $key) {
    [$controller, $provider, , $db] = gstReplayFixture();

    $response = $controller->handle(gstReplayRequest(gstReplayBody(), $key), 'samsara');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Invalid idempotency key'])
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
})->with([
    'empty'     => [''],
    'too long'  => [str_repeat('k', 257)],
    'space'     => ['key with space'],
    'tab'       => ["key\twith-tab"],
    'non ascii' => ["cl\u{e9}"],
]);

test('the longest accepted idempotency key is processed', function () {
    [$controller, , , $db] = gstReplayFixture();

    $response = $controller->handle(gstReplayRequest(gstReplayBody(), str_repeat('k', 256)), 'samsara');

    expect($response->getData(true))->toBe(['status' => 'processed'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed']);
});

test('oversized authenticated bodies are rejected with 413 before reservation', function () {
    [$controller, $provider, , $db] = gstReplayFixture();
    $body                           = gstReplayBody('big', ['pad' => str_repeat('x', WebhookReplayLedger::MAX_BODY_BYTES)]);

    $response = $controller->handle(gstReplayRequest($body), 'samsara');

    expect(strlen($body))->toBeGreaterThan(WebhookReplayLedger::MAX_BODY_BYTES)
        ->and($response->getStatusCode())->toBe(413)
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
});

test('authentication is checked before replay protection', function () {
    [$controller, $provider, , $db] = gstReplayFixture();
    $db->table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_one', 'company_uuid' => 'company-1', 'provider' => 'samsara']);
    $provider->signatureValid = false;

    $forged      = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $forgedLarge = $controller->handle(gstReplayRequest(gstReplayBody('x', ['pad' => str_repeat('x', WebhookReplayLedger::MAX_BODY_BYTES)]), str_repeat('k', 300)), 'samsara');
    $badIngest   = $controller->ingest(gstReplayRequest(gstReplayBody(), 'key-1', 'not-the-hmac'), 'integration-1');

    expect($forged->getStatusCode())->toBe(403)
        // Unauthenticated callers learn nothing about key or size validation.
        ->and($forgedLarge->getStatusCode())->toBe(403)
        ->and($badIngest->getStatusCode())->toBe(403)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
});

test('an unavailable replay ledger fails closed with a retryable 503 and no business write', function () {
    [$controller, $provider, , $db, $log] = gstReplayFixture();
    $db->getSchemaBuilder()->drop(WebhookReplayLedger::TABLE);

    $response = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $failure  = array_values(array_filter($log->records, fn ($r) => $r['message'] === 'Webhook replay ledger unavailable'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe((string) TelematicWebhookController::RETRY_AFTER_SECONDS)
        ->and($response->getData(true))->toBe(['error' => 'Webhook temporarily unavailable'])
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and($failure)->toHaveCount(1)
        ->and($failure[0]['level'])->toBe('error')
        ->and($failure[0]['context'])->toHaveKeys(['exception', 'sqlstate', 'driver_code'])
        // The SQL statement and its bindings (payload data) are never logged.
        ->and(strtolower(json_encode($failure[0]['context'])))->not->toContain('insert');
});

test('in-progress and unreconciled receipts are never processed twice', function () {
    [$controller, $provider, , $db, $log] = gstReplayFixture();
    $ledger                               = new WebhookReplayLedger();
    $claim                                = $ledger->claim(gstReplayScope(gstReplayTelematic()), gstReplayBody(), 'key-1');

    $inProgress = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    // A worker crashed after its lease: business data may exist, never steal it.
    $db->table(WebhookReplayLedger::TABLE)->update(['lease_expires_at' => '2000-01-01 00:00:00']);
    $stale = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    // An operator confirms the effects were applied: later replays are duplicates.
    $resolved = $ledger->resolveStaleAsProcessed($claim['scope_hash'], $claim['receipt_key']);
    $after    = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    expect($claim['decision'])->toBe(WebhookReplayLedger::ACQUIRED)
        ->and($inProgress->getStatusCode())->toBe(503)
        ->and($inProgress->getData(true))->toBe(['error' => 'Webhook delivery already in progress'])
        ->and($inProgress->headers->get('Retry-After'))->toBe('30')
        ->and($stale->getStatusCode())->toBe(503)
        ->and($stale->getData(true))->toBe(['error' => 'Webhook delivery is being reconciled'])
        ->and($stale->headers->get('Retry-After'))->toBe('30')
        ->and($log->messages('error'))->toContain('Webhook receipt needs reconciliation')
        ->and($resolved)->toBeTrue()
        ->and($after->getData(true))->toBe(['status' => 'duplicate'])
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([]);
});

test('a stale receipt released by an operator is processed by the next delivery', function () {
    [$controller, , , $db] = gstReplayFixture();
    $ledger                = new WebhookReplayLedger();
    $claim                 = $ledger->claim(gstReplayScope(gstReplayTelematic()), gstReplayBody(), 'key-1');
    $db->table(WebhookReplayLedger::TABLE)->update(['lease_expires_at' => '2000-01-01 00:00:00']);

    expect($ledger->releaseStaleWithoutEffects($claim['scope_hash'], $claim['receipt_key']))->toBeTrue()
        ->and($controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara')->getData(true))->toBe(['status' => 'processed'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed']);
});

test('integrations that cannot be scoped are never processed unprotected', function (string $company, string $uuid) {
    [$controller, $provider, $service, $db, $log] = gstReplayFixture();
    $service->telematic                           = gstReplayTelematic($company, $uuid);

    $response = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['error' => 'Processing failed'])
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([])
        ->and($log->messages('error'))->toContain('Webhook rejected: integration cannot be scoped for replay protection');
})->with([
    'no company'     => ['', 'integration-1'],
    'no integration' => ['company-1', ''],
]);

test('a caller-owned transaction is refused without business effects', function () {
    [$controller, $provider, , $db] = gstReplayFixture();

    $db->beginTransaction();
    $response = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $db->rollBack();

    expect($response->getStatusCode())->toBe(503)
        ->and($provider->processed)->toBe(0)
        ->and(gstReplayBusiness($db))->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
});

test('a business failure after a write rolls back the write, the receipt and deferred effects; the retry is processed', function () {
    [$controller, , $service, $db] = gstReplayFixture();
    $service->failAfterWrite       = true;

    $failed            = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $stateAfterFailure = [gstReplayBusiness($db), WebhookReceiptDatabase::statuses($db), $service->published];

    $service->failAfterWrite = false;
    $retry                   = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    expect($failed->getStatusCode())->toBe(500)
        ->and($failed->getData(true))->toBe(['error' => 'Processing failed'])
        ->and($stateAfterFailure)->toBe([[], [], []])
        ->and($retry->getData(true))->toBe(['status' => 'processed'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1'])
        ->and($service->published)->toBe(['m1'])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe(['processed']);
});

test('a custom ingest failure after a write rolls everything back', function () {
    [$controller, , $service, $db] = gstReplayFixture();
    $db->table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_one', 'company_uuid' => 'company-1', 'provider' => 'samsara']);
    $service->failAfterWrite = true;

    $failed = $controller->ingest(gstReplayIngestRequest(gstReplayBody(), 'key-1'), 'telematic_one');

    expect($failed->getStatusCode())->toBe(500)
        ->and($failed->getData(true))->toBe(['error' => 'Ingest failed'])
        ->and(gstReplayBusiness($db))->toBe([])
        ->and($service->published)->toBe([])
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
});

test('losing the receipt between the business write and finalization rolls the write back', function () {
    [$controller, , $service, $db] = gstReplayFixture();
    $service->loseReceipt          = true;

    $lost           = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');
    $stateAfterLoss = [gstReplayBusiness($db), WebhookReceiptDatabase::statuses($db), $service->published];

    $service->loseReceipt = false;
    $retry                = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1'), 'samsara');

    expect($lost->getStatusCode())->toBe(503)
        ->and($lost->headers->get('Retry-After'))->toBe('30')
        ->and($stateAfterLoss)->toBe([[], [], []])
        ->and($retry->getData(true))->toBe(['status' => 'processed'])
        ->and(gstReplayBusiness($db))->toBe(['integration-1:m1'])
        ->and($service->published)->toBe(['m1']);
});

test('webhook audit logs carry no secret, key, signature, body or SQL', function () {
    [$controller, , , $db, $log] = gstReplayFixture();
    $key                         = 'idem-SECRET-4c2e';
    $body                        = gstReplayBody('BODY-SECRET-91aa');

    $controller->handle(gstReplayRequest($body, $key), 'samsara');
    $controller->handle(gstReplayRequest($body, $key), 'samsara');
    $controller->handle(gstReplayRequest(gstReplayBody('BODY-SECRET-other'), $key), 'samsara');
    $controller->handle(gstReplayRequest($body, $key . ' invalid'), 'samsara');
    $db->getSchemaBuilder()->drop(WebhookReplayLedger::TABLE);
    $controller->handle(gstReplayRequest($body, $key), 'samsara');

    $dump     = json_encode($log->records, JSON_THROW_ON_ERROR);
    $receipts = array_values(array_filter(array_map(fn ($r) => $r['context']['receipt'] ?? null, $log->records)));

    expect($log->messages())->toContain('Webhook processed successfully', 'Authenticated duplicate webhook', 'Webhook rejected: idempotency key reused with a different payload', 'Webhook rejected: invalid idempotency key', 'Webhook replay ledger unavailable')
        ->and($dump)->not->toContain(GST_REPLAY_SIGNATURE)
        ->and($dump)->not->toContain(GST_REPLAY_PROVIDER_SECRET)
        ->and($dump)->not->toContain(GST_REPLAY_INGEST_SECRET)
        ->and($dump)->not->toContain('SECRET-4c2e')
        ->and($dump)->not->toContain('BODY-SECRET')
        ->and(strtolower($dump))->not->toContain('insert into')
        ->and(strtolower($dump))->not->toContain('select ')
        ->and($receipts)->not->toBeEmpty();
    foreach ($receipts as $receipt) {
        // A truncated hash of the key, never the key itself.
        expect($receipt)->toMatch('/\A[0-9a-f]{16}\z/');
    }
});

test('non-database telemetry effects wait for the commit and are discarded on rollback', function () {
    [, , $service, $db] = gstReplayFixture();
    $telematic          = gstReplayTelematic();
    $ran                = [];

    $db->beginTransaction();
    $service->defer($telematic, function () use (&$ran) { $ran[] = 'committed'; });
    $beforeCommit = $ran;
    $db->commit();

    $db->beginTransaction();
    $service->defer($telematic, function () use (&$ran) { $ran[] = 'rolled-back'; });
    $db->rollBack();

    $service->defer($telematic, function () use (&$ran) { $ran[] = 'immediate'; });

    $db->beginTransaction();
    $service->broadcastFor($event = new stdClass(), $telematic);
    $broadcastBeforeCommit = $GLOBALS['fleetops_telematic_service_broadcasts'];
    $db->commit();

    EloquentModel::unsetConnectionResolver();
    $service->defer($telematic, function () use (&$ran) { $ran[] = 'no-database'; });

    expect($beforeCommit)->toBe([])
        ->and($ran)->toBe(['committed', 'immediate', 'no-database'])
        ->and($broadcastBeforeCommit)->toBe([])
        ->and($GLOBALS['fleetops_telematic_service_broadcasts'])->toBe([$event]);
});

test('telemetry provider deliveries keep their own pipeline and bypass the uniform signature and the ledger', function () {
    [$controller, , $service, $db, , , $registry] = gstReplayFixture();
    $registry->override                           = new GstReplayTelemetryProvider();
    $position                                     = new GstReplayPositionControllerFake();
    app()->instance(TelematicPositionWebhookController::class, $position);

    $response = $controller->handle(gstReplayRequest(gstReplayBody(), 'key-1', null), 'afaqy');

    expect($response->getStatusCode())->toBe(202)
        ->and($position->calls)->toBe([['afaqy', false]])
        ->and($service->calls)->toBe(0)
        ->and(WebhookReceiptDatabase::statuses($db))->toBe([]);
});
