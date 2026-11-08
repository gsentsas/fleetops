<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Http\Controllers\TelematicWebhookController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Covers the telematic webhook controller's custom ingest endpoint and the
 * signature-based telematic resolution branch of the provider webhook handler
 * against an in-memory SQLite fixture with service, registry, and idempotency
 * fakes.
 */
class FleetOpsWebhookIngestServiceFake extends TelematicService
{
    public array $linked          = [];
    public array $events          = [];
    public array $sensors         = [];
    public bool $eventThrows      = false;
    public ?Telematic $resolved   = null;
    public ?string $webhookSecret = 'fixture-hmac-secret';

    public function __construct()
    {
    }

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        if (empty($deviceData['external_id']) && empty($deviceData['imei'])) {
            throw ValidationException::withMessages(['external_id' => ['required']]);
        }

        $this->linked[] = $deviceData;
        $device         = new Device();
        $device->setRawAttributes(['uuid' => 'device-' . count($this->linked)], true);

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        if ($this->eventThrows) {
            throw new Exception('event storage failed');
        }

        $this->events[] = [$eventData, $device?->uuid];
        $event          = new DeviceEvent();
        $event->setRawAttributes(['uuid' => 'event-' . count($this->events)], true);

        return $event;
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        if (empty($sensorData['name'])) {
            throw ValidationException::withMessages(['name' => ['required']]);
        }

        $this->sensors[] = [$sensorData, $device?->uuid];
        $sensor          = new Sensor();
        $sensor->setRawAttributes(['uuid' => 'sensor-' . count($this->sensors)], true);

        return $sensor;
    }

    public function getCredentials(Telematic $telematic): array
    {
        return ['token' => 'secret', 'webhook_secret' => $this->webhookSecret];
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        return $this->resolved;
    }
}

class FleetOpsWebhookIngestIdempotencyFake extends IdempotencyManager
{
    public array $processed   = [];
    public bool $duplicate    = false;

    public function __construct()
    {
    }

    public function isDuplicate(string $key): bool
    {
        return $this->duplicate;
    }

    public function markProcessed(string $key): void
    {
        $this->processed[] = $key;
    }
}

class FleetOpsWebhookIngestProviderStub implements TelematicProviderInterface
{
    public array $validSignatures = [];

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true];
    }

    public function fetchDevices(array $options = []): array
    {
        return [];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return [];
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
        return in_array($signature, $this->validSignatures, true);
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return ['devices' => [], 'events' => [], 'sensors' => []];
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

class FleetOpsWebhookIngestRegistryFake extends TelematicProviderRegistry
{
    public ?TelematicProviderInterface $provider = null;

    public function resolve(string $key): TelematicProviderInterface
    {
        return $this->provider ?? parent::resolve($key);
    }
}

function fleetopsWebhookIngestBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('telematics', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'meta'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    // The durable replay ledger the controller now uses (the idempotency fake is no longer consulted).
    Fleetbase\Tests\Support\WebhookReceiptDatabase::migrate($connection);

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsWebhookIngestController(
    ?FleetOpsWebhookIngestServiceFake $service = null,
    ?FleetOpsWebhookIngestIdempotencyFake $idempotency = null,
    ?TelematicProviderRegistry $registry = null,
): TelematicWebhookController {
    return new TelematicWebhookController(
        $registry ?? new TelematicProviderRegistry(),
        $service ?? new FleetOpsWebhookIngestServiceFake(),
        $idempotency ?? new FleetOpsWebhookIngestIdempotencyFake()
    );
}

function fleetopsWebhookIngestSignedRequest(array $payload = []): Request
{
    // JSON body is intentionally nonempty: verify HMAC on the actual raw payload.
    $body    = json_encode($payload, JSON_THROW_ON_ERROR);
    $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $request->headers->set('X-Webhook-Signature', hash_hmac('sha256', $body, 'fixture-hmac-secret'));

    return $request;
}

test('ingest throws for unknown telematic identifiers', function () {
    fleetopsWebhookIngestBoot();

    expect(fn () => fleetopsWebhookIngestController()->ingest(fleetopsWebhookIngestSignedRequest(), 'missing'))
        ->toThrow(ModelNotFoundException::class);
});

test('ingest short circuits duplicate idempotency keys', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    // A real replay through the durable ledger: the second identical signed
    // delivery is a duplicate and its business effects are not applied again.
    $service    = new FleetOpsWebhookIngestServiceFake();
    $controller = fleetopsWebhookIngestController($service);
    $delivery   = function () {
        $request = fleetopsWebhookIngestSignedRequest(['events' => [['type' => 'location']]]);
        $request->headers->set('X-Idempotency-Key', 'idem-1');

        return $request;
    };

    $first    = $controller->ingest($delivery(), 'telematic_test');
    $response = $controller->ingest($delivery(), 'telematic_test');

    expect($first->getData(true))->toBe(['status' => 'ingested'])
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'duplicate'])
        ->and($service->events)->toHaveCount(1)
        ->and(Fleetbase\Tests\Support\WebhookReceiptDatabase::statuses($connection))->toBe(['processed']);
});

test('ingest links devices stores events and sensors and marks processed', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    $service     = new FleetOpsWebhookIngestServiceFake();
    $idempotency = new FleetOpsWebhookIngestIdempotencyFake();

    $request = fleetopsWebhookIngestSignedRequest([
        'devices' => [
            ['external_id' => 'ext-1', 'name' => 'Tracker'],
            ['name' => 'No identity device'],
        ],
        'events' => [
            ['device_id' => 'ext-1', 'type' => 'location'],
            ['type' => 'orphan-event'],
        ],
        'sensors' => [
            ['device_id' => 'ext-1', 'name' => 'Temp'],
            ['device_id' => 'ext-1'],
        ],
    ]);
    $request->headers->set('X-Idempotency-Key', 'idem-2');

    $response = fleetopsWebhookIngestController($service, $idempotency)->ingest($request, 'telematic-1');

    expect($response->getData(true))->toBe(['status' => 'ingested'])
        ->and($service->linked)->toHaveCount(1)
        ->and($service->events)->toHaveCount(2)
        ->and($service->events[0][1])->toBe('device-1')
        ->and($service->events[1][1])->toBeNull()
        ->and($service->sensors)->toHaveCount(1)
        // Recorded as a durable, tenant-scoped receipt, not in the global cache manager.
        ->and(Fleetbase\Tests\Support\WebhookReceiptDatabase::statuses($connection))->toBe(['processed'])
        ->and($idempotency->processed)->toBe([]);
});

test('ingest reports processing failures', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    $service              = new FleetOpsWebhookIngestServiceFake();
    $service->eventThrows = true;

    $request  = fleetopsWebhookIngestSignedRequest(['events' => [['type' => 'boom']]]);
    $response = fleetopsWebhookIngestController($service)->ingest($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['error' => 'Ingest failed'])
        // The receipt rolled back with the failed processing: a retry is processed again.
        ->and(Fleetbase\Tests\Support\WebhookReceiptDatabase::statuses($connection))->toBe([]);
});

test('handle resolves the telematic by unique signature match', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'stub']);

    $provider                  = new FleetOpsWebhookIngestProviderStub();
    $provider->validSignatures = ['sig-valid'];
    $registry                  = new FleetOpsWebhookIngestRegistryFake();
    $registry->provider        = $provider;

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-Webhook-Signature', 'sig-valid');

    $response = fleetopsWebhookIngestController(null, null, $registry)->handle($request, 'stub');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'processed']);
});

test('handle rejects ambiguous signature matches', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'telematic-1', 'public_id' => 'telematic_a', 'company_uuid' => 'company-1', 'provider' => 'stub', 'meta' => null],
        ['uuid' => 'telematic-2', 'public_id' => 'telematic_b', 'company_uuid' => 'company-1', 'provider' => 'stub', 'meta' => null],
    ]);

    $provider                  = new FleetOpsWebhookIngestProviderStub();
    $provider->validSignatures = ['sig-shared'];
    $registry                  = new FleetOpsWebhookIngestRegistryFake();
    $registry->provider        = $provider;

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-Webhook-Signature', 'sig-shared');

    $response = fleetopsWebhookIngestController(null, null, $registry)->handle($request, 'stub');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toBe(['error' => 'Ambiguous telematic integration']);
});
test('rejects an unsigned ingest request before integration lookup', function () {
    fleetopsWebhookIngestBoot();
    $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"events":[]}');

    $response = fleetopsWebhookIngestController()->ingest($request, 'nonexistent-integration');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe(['error' => 'Invalid signature']);
});

test('rejects an invalid custom ingest HMAC before any business writes', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);
    $service     = new FleetOpsWebhookIngestServiceFake();
    $idempotency = new FleetOpsWebhookIngestIdempotencyFake();
    $request     = fleetopsWebhookIngestSignedRequest(['events' => [['type' => 'untrusted']]]);
    $request->headers->set('X-Webhook-Signature', str_repeat('0', 64));
    $request->headers->set('X-Idempotency-Key', 'untrusted-key');

    $response = fleetopsWebhookIngestController($service, $idempotency)->ingest($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(403)
        ->and($service->linked)->toBe([])
        ->and($service->events)->toBe([])
        ->and($service->sensors)->toBe([])
        ->and($idempotency->processed)->toBe([])
        // Authentication happens before any receipt is reserved.
        ->and(Fleetbase\Tests\Support\WebhookReceiptDatabase::statuses($connection))->toBe([]);
});

test('rejects an ingest request if the integration webhook secret is absent', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);
    $service                = new FleetOpsWebhookIngestServiceFake();
    $service->webhookSecret = null;
    $request                = fleetopsWebhookIngestSignedRequest(['events' => [['type' => 'untrusted']]]);

    $response = fleetopsWebhookIngestController($service)->ingest($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(403)
        ->and($service->events)->toBe([])
        ->and($service->linked)->toBe([]);
});

test('custom ingest signature is bound to the original request body', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);
    $service  = new FleetOpsWebhookIngestServiceFake();
    $original = fleetopsWebhookIngestSignedRequest(['events' => [['type' => 'legitimate']]]);
    $modified = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['events' => [['type' => 'modified']]], JSON_THROW_ON_ERROR));
    $modified->headers->set('X-Webhook-Signature', $original->headers->get('X-Webhook-Signature'));

    $response = fleetopsWebhookIngestController($service)->ingest($modified, 'telematic_test');

    expect($response->getStatusCode())->toBe(403)
        ->and($service->events)->toBe([])
        ->and($service->linked)->toBe([]);
});
