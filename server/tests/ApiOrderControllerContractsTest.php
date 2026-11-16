<?php

use Fleetbase\FleetOps\Exceptions\OrderEditLockedException;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateOrderRequest;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\OrderEdit;
use Fleetbase\FleetOps\Support\OrderEditPolicy;
use Fleetbase\FleetOps\Support\OrderTracker;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Disposable in-memory database for transaction boundaries and QR duplicate reads.
$capsule = new Illuminate\Database\Capsule\Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
$capsule->setAsGlobal();
$capsule->bootEloquent();
config(['database.default' => 'mysql', 'database.connections.mysql' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
]]);

$capsule->schema('mysql')->create('proofs', static function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->increments('id');
    $table->string('company_uuid')->nullable();
    $table->string('order_uuid')->nullable();
    $table->string('subject_uuid')->nullable();
    $table->string('subject_type')->nullable();
    $table->string('remarks')->nullable();
    $table->softDeletes();
});

function fleetopsProofErrorResponse(mixed $response): array
{
    if ($response instanceof Illuminate\Http\JsonResponse) {
        return ['apiError' => $response->getData(true)['error'], 'status' => $response->getStatusCode()];
    }

    return $response;
}

function fleetopsValidTestPng(): string
{
    $bitmap = imagecreatetruecolor(2, 2);
    imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 34, 102, 170));
    ob_start();
    imagepng($bitmap);
    $contents = ob_get_clean();
    imagedestroy($bitmap);

    return $contents;
}

class FleetOpsApiOrderCrudControllerProbe extends OrderController
{
    public ?FleetOpsApiOrderCrudFake $order      = null;
    public mixed $matrix                         = null;
    public array $createdProofs                  = [];
    public array $createdFiles                   = [];
    public array $storedFiles                    = [];
    public array $proofCollections               = [];
    public array $commentCollections             = [];
    public array $createdOrders                  = [];
    public array $finalizedOrders                = [];
    public array $uuidLookups                    = [];
    public array $createdContacts                = [];
    public mixed $entityEditingSettings          = null;
    public ?OrderConfig $orderConfig             = null;
    public ?ServiceQuote $serviceQuote           = null;
    public ?FleetOpsApiOrderPayloadFake $payload = null;
    public ?Contact $customerContact             = null;
    public ?FleetOpsApiOrderDriverFake $driver   = null;
    public bool $orderNotFound                   = false;
    public bool $missingOrderConfig              = false;
    public mixed $resolvedSubject                = null;
    public ?string $companyUuid                  = 'company-uuid';
    public ?FleetOpsApiOrderEditFake $edit       = null;
    public array $editLockedWith                 = [];

    protected function findOrder(string $id, array $with = [], array $withCount = [], bool $lockForUpdate = false): Order
    {
        if ($this->orderNotFound) {
            throw new ModelNotFoundException();
        }

        $this->order ??= new FleetOpsApiOrderCrudFake();
        $this->order->lookups[] = [$id, $with, $withCount];

        return $this->order;
    }

    public ?FleetOpsApiOrderPayloadFake $switchedPayload = null;

    protected function findPayloadByUuid(?string $uuid): ?Payload
    {
        return $this->switchedPayload;
    }

    protected function beginOrderEdit(Order $order): OrderEdit
    {
        $this->edit ??= new FleetOpsApiOrderEditFake($order);
        $this->edit->lockWith = $this->editLockedWith;

        return $this->edit;
    }

    protected function lockedEditResponse(OrderEditLockedException $exception)
    {
        return ['apiError' => $exception->getMessage(), 'status' => 422, 'locked' => $exception->locked];
    }

    protected function drivingDistanceAndTime(mixed $origin, mixed $destination): mixed
    {
        return $this->matrix ?? (object) ['distance' => 1200, 'time' => 360];
    }

    protected function resolveOrderConfig(array $input): ?OrderConfig
    {
        if ($this->missingOrderConfig) {
            return null;
        }

        return $this->orderConfig ?? tap(new OrderConfig(), function (OrderConfig $config) {
            $config->setRawAttributes(['uuid' => 'order-config-uuid', 'key' => 'transport'], true);
        });
    }

    protected function resolveServiceQuote(Request $request): ?ServiceQuote
    {
        return $this->serviceQuote;
    }

    protected function newPayload(): Payload
    {
        return $this->payload = new FleetOpsApiOrderPayloadFake();
    }

    protected function findDriverByPublicId(?string $publicId): ?Fleetbase\FleetOps\Models\Driver
    {
        $this->driver ??= new FleetOpsApiOrderDriverFake();
        $this->driver->setRawAttributes([
            'uuid'         => 'driver-uuid',
            'public_id'    => $publicId,
            'vehicle_uuid' => 'driver-vehicle-uuid',
        ], true);

        return $this->driver;
    }

    protected function sessionCompany(): ?string
    {
        return $this->companyUuid;
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        $this->uuidLookups[] = [$table, $where, $options];

        if (is_array($table)) {
            return ['uuid' => implode('-', $table) . '-uuid', 'table' => $table[0]];
        }

        return $table . '-uuid';
    }

    protected function getModelClassName(mixed $tableOrModel): ?string
    {
        if ($tableOrModel instanceof Contact) {
            return Contact::class;
        }

        return match ($tableOrModel) {
            'contacts'           => Contact::class,
            'vendors'            => 'Fleetbase\\FleetOps\\Models\\Vendor',
            'integrated_vendors' => 'Fleetbase\\FleetOps\\Models\\IntegratedVendor',
            default              => is_string($tableOrModel) ? $tableOrModel : null,
        };
    }

    protected function newCustomerContact(array $attributes): Contact
    {
        $contact = new FleetOpsApiOrderContactFake();
        $contact->setRawAttributes(array_merge(['uuid' => 'candidate-contact-uuid'], $attributes), true);

        return $contact;
    }

    protected function firstOrCreateCustomerContact(array $attributes, array $values): Contact
    {
        $this->createdContacts[] = [$attributes, $values];
        $this->customerContact ??= new FleetOpsApiOrderContactFake();
        $this->customerContact->setRawAttributes(array_merge(['uuid' => 'customer-contact-uuid'], $values), true);

        return $this->customerContact;
    }

    protected function createOrder(array $input): Order
    {
        $this->createdOrders[] = $input;
        $this->order ??= new FleetOpsApiOrderCrudFake();
        $this->order->setRawAttributes(array_merge(['uuid' => 'created-order-uuid'], $input), true);

        return $this->order;
    }

    protected function dispatchFinalizeApiOrderCreation(string $orderUuid, ?string $serviceQuoteUuid, bool $shouldDispatch, string $companyUuid): void
    {
        $this->finalizedOrders[] = [$orderUuid, $serviceQuoteUuid, $shouldDispatch, $companyUuid];
    }

    protected function resolveSubject(Order $order, ?string $type, ?string $subjectId = null): mixed
    {
        return $this->resolvedSubject;
    }

    protected function requireFreshProofAuthorizationSnapshot(): void
    {
        // This unit probe uses a disposable SQLite transaction; authorization is
        // verified in dedicated HTTP/revocation integration tests.
    }

    protected function lockProofCaptureSubject(Order $order, ?string $type, ?string $subjectId): array
    {
        $locked  = $this->findOrder($order->public_id, [], [], true);
        $subject = $this->resolveSubject($locked, $type, $subjectId);
        if (!$subject) {
            throw new Illuminate\Http\Exceptions\HttpResponseException(new Illuminate\Http\JsonResponse(['error' => 'Proof subject is unavailable.'], 409));
        }

        return [$locked, $subject];
    }

    protected function createProof(array $input): Proof
    {
        $this->createdProofs[] = $input;

        $proof = new FleetOpsApiOrderProofFake();
        $proof->setRawAttributes(array_merge(['uuid' => 'proof-uuid', 'public_id' => 'proof_public'], $input));

        return $proof;
    }

    protected function createFile(array $input): File
    {
        $this->createdFiles[] = $input;

        $file = new FleetOpsApiOrderFileFake();
        $file->setRawAttributes(array_merge(['uuid' => 'file-uuid'], $input));

        return $file;
    }

    protected function putStorage(string $disk, string $path, string $contents): void
    {
        $this->storedFiles[] = compact('disk', 'path', 'contents');
    }

    protected function entityEditingSettings(): mixed
    {
        return $this->entityEditingSettings;
    }

    protected function defaultCompanyTimezone(): string
    {
        return 'UTC';
    }

    protected function orderResource(Order $order)
    {
        return ['resource' => 'order', 'order' => $order];
    }

    protected function deletedOrderResource(Order $order)
    {
        return ['resource' => 'deleted-order', 'order' => $order];
    }

    protected function proofResource(Proof $proof)
    {
        return ['resource' => 'proof', 'proof' => $proof];
    }

    protected function proofsForSubject(Order $order, mixed $subject): Collection
    {
        return collect([
            (new FleetOpsApiOrderProofFake())->setRawAttributes([
                'uuid'         => 'proof-one',
                'order_uuid'   => $order->uuid,
                'subject_uuid' => $subject->uuid,
            ]),
        ]);
    }

    protected function proofResourceCollection($proofs)
    {
        $this->proofCollections[] = $proofs;

        return ['resource' => 'proofs', 'proofs' => $proofs->values()->all()];
    }

    protected function commentResourceCollection($comments)
    {
        $this->commentCollections[] = $comments;

        return ['resource' => 'comments', 'comments' => $comments->values()->all()];
    }

    protected function jsonResponse(mixed $payload, int $status = 200)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

/**
 * Records the edit guard and commit instead of reading and announcing through the database.
 */
class FleetOpsApiOrderEditFake extends OrderEdit
{
    public array $guarded  = [];
    public array $lockWith = [];
    public int $commits    = 0;

    public function __construct(public Order $order)
    {
        $this->policy = OrderEditPolicy::for($order);
    }

    public function requestedChanges(array $input, string $entitiesMode = self::ENTITIES_APPEND): array
    {
        return ['input' => $input, 'entities_mode' => $entitiesMode];
    }

    public function guard(array $changes): static
    {
        $this->guarded[] = $changes;
        if ($this->lockWith) {
            throw new OrderEditLockedException($this->lockWith);
        }

        return $this;
    }

    public function commit(array $extra = [], bool $notifyDriver = true): array
    {
        $this->commits++;

        return [];
    }
}

class FleetOpsApiOrderCrudFake extends Order
{
    public array $lookups                  = [];
    public array $loaded                   = [];
    public array $updates                  = [];
    public bool $deletedForTest            = false;
    public bool $dispatchedForTest         = false;
    public bool $dispatchActivityInserted  = false;
    public bool $cancelledForTest          = false;
    public bool $hasDriverAssignedForTest  = true;
    public bool $adhocForTest              = false;
    public bool $dispatchedFlagForTest     = false;
    public bool $savedForTest              = false;
    public bool $refreshedForTest          = false;
    public bool $flushedForTest            = false;
    public array $metaUpdates              = [];
    public array $purchasedQuotes          = [];
    public FleetOpsApiOrderTrackerFake $trackerFake;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->trackerFake  = new FleetOpsApiOrderTrackerFake($this);
        $this->uuid         = $attributes['uuid'] ?? 'order-uuid';
        $this->company_uuid = $attributes['company_uuid'] ?? 'company-uuid';
        $this->public_id    = $attributes['public_id'] ?? 'order-public';
        $this->status       = $attributes['status'] ?? 'created';
        $this->payload      = (object) [
            'pickup'    => (object) ['public_id' => 'pickup-public'],
            'dropoff'   => (object) ['public_id' => 'dropoff-public'],
            'waypoints' => collect([(object) ['public_id' => 'waypoint-public']]),
        ];
        $this->comments = collect([(object) ['body' => 'Looks good']]);
    }

    public function getHasDriverAssignedAttribute(): bool
    {
        return $this->hasDriverAssignedForTest;
    }

    public function getAdhocAttribute(): bool
    {
        return $this->adhocForTest;
    }

    public function getDispatchedAttribute(): bool
    {
        return $this->dispatchedFlagForTest;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function refresh()
    {
        $this->refreshedForTest = true;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->flushedForTest = true;

        return true;
    }

    public function updateMeta($key, $value = null)
    {
        $this->metaUpdates[] = [$key, $value];

        return true;
    }

    public function purchaseServiceQuote($serviceQuote, $meta = [])
    {
        $this->purchasedQuotes[] = $serviceQuote;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }

    public function dispatch(bool $save = true): Order
    {
        $this->dispatchedForTest = true;

        return $this;
    }

    public function insertDispatchActivity(): Order
    {
        $this->dispatchActivityInserted = true;

        return $this;
    }

    public function cancel()
    {
        $this->cancelledForTest = true;

        return $this;
    }

    public ?string $cancelReasonForTest = null;

    public function cancelWithReason(?string $reason = null)
    {
        $this->cancelledForTest    = true;
        $this->cancelReasonForTest = $reason;

        return $this;
    }

    public function tracker(): OrderTracker
    {
        return $this->trackerFake;
    }
}

class FleetOpsApiOrderPayloadFake extends Payload
{
    public array $calls           = [];
    public bool $savedForTest     = false;
    public bool $removedWaypoints = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->uuid = 'payload-built-uuid';
    }

    public function setPickup($place, array $options = [])
    {
        $this->calls[] = ['pickup', $place];
        if (isset($options['callback'])) {
            $options['callback'](new Place(), $this);
        }

        return $this;
    }

    public function setDropoff($place, array $options = [])
    {
        $this->calls[] = ['dropoff', $place];

        return $this;
    }

    public function setReturn($place, array $options = [])
    {
        $this->calls[] = ['return', $place];

        return $this;
    }

    public function setWaypoints($waypoints = [])
    {
        $this->calls[] = ['waypoints', $waypoints];

        return $this;
    }

    public function removeWaypoints()
    {
        $this->removedWaypoints = true;

        return $this;
    }

    public function setEntities($entities = [])
    {
        $this->calls[] = ['entities', $entities];

        return $this;
    }

    public function syncEntities($entities = []): array
    {
        $this->calls[] = ['sync-entities', $entities];

        return ['created' => [], 'updated' => [], 'deleted' => []];
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        $this->calls[] = ['current', $destination instanceof Place, $save];

        return $this;
    }

    public function getPickupOrFirstWaypoint(): ?Place
    {
        return new Place();
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }
}

class FleetOpsApiOrderContactFake extends Contact
{
    public function assertCustomerIdentityIsAvailable(): void
    {
    }
}

class FleetOpsApiOrderDriverFake extends Fleetbase\FleetOps\Models\Driver
{
}

class FleetOpsApiOrderProofFake extends Proof
{
    public array $updates = [];
    public bool $saved    = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }
}

class FleetOpsApiOrderFileFake extends File
{
    public mixed $keyedTo = null;

    public function setKey($model, $type = null): File
    {
        $this->keyedTo = $model;

        return $this;
    }
}

class FleetOpsApiOrderTrackerFake extends OrderTracker
{
    public array $toArrayOptions = [];
    public array $etaOptions     = [];
    public bool $throwOnTrack    = false;

    public function toArray(array $options = []): array
    {
        if ($this->throwOnTrack) {
            throw new RuntimeException('tracking failed');
        }

        $this->toArrayOptions = $options;

        return ['tracking' => 'ok', 'options' => $options];
    }

    public function eta(array $options = []): array
    {
        if ($this->throwOnTrack) {
            throw new RuntimeException('eta failed');
        }

        $this->etaOptions = $options;

        return ['eta' => 600, 'options' => $options];
    }
}

class FleetOpsApiOrderScheduleRequestFake extends ScheduleOrderRequest
{
    public function __construct(private readonly array $inputForTest)
    {
        parent::__construct();
    }

    public function input($key = null, $default = null)
    {
        if ($key === null) {
            return $this->inputForTest;
        }

        return data_get($this->inputForTest, $key, $default);
    }
}

class FleetOpsApiOrderCreateRequestFake extends CreateOrderRequest
{
    public function isArray(string $key): bool
    {
        return is_array($this->input($key));
    }

    public function isString(string $key): bool
    {
        return is_string($this->input($key));
    }
}

class FleetOpsApiOrderUpdateRequestFake extends UpdateOrderRequest
{
    public function isArray(string $key): bool
    {
        return is_array($this->input($key));
    }

    public function isString(string $key): bool
    {
        return is_string($this->input($key));
    }
}

test('api order controller creates orders with payloads assignments customers and finalize dispatch', function () {
    $controller = new FleetOpsApiOrderCrudControllerProbe();
    $quote      = new ServiceQuote();
    $quote->setRawAttributes(['uuid' => 'service-quote-uuid'], true);
    $controller->serviceQuote = $quote;

    $response = $controller->create(new FleetOpsApiOrderCreateRequestFake([
        'type'                  => 'transport',
        'payload'               => [
            'pickup'    => ['name' => 'Pickup'],
            'dropoff'   => ['name' => 'Dropoff'],
            'return'    => ['name' => 'Return'],
            'waypoints' => [['name' => 'Stop']],
            'entities'  => [['name' => 'Box']],
        ],
        'driver'                => 'driver_public',
        'vehicle'               => 'vehicle_public',
        'facilitator'           => 'facilitator_public',
        'customer'              => [
            'name'  => 'Jane Customer',
            'email' => 'jane@example.test',
            'phone' => '+15551234567',
            'meta'  => ['tier' => 'gold'],
        ],
        'adhoc'                 => 'true',
        'orchestrator_priority' => null,
        'dispatch'              => true,
    ]));

    expect($response)->toBe(['resource' => 'order', 'order' => $controller->order])
        ->and($controller->payload->savedForTest)->toBeTrue()
        ->and($controller->payload->calls)->toContain(
            ['pickup', ['name' => 'Pickup']],
            ['dropoff', ['name'    => 'Dropoff']],
            ['return', ['name'     => 'Return']],
            ['waypoints', [['name' => 'Stop']]],
            ['entities', [['name'  => 'Box']]]
        )
        ->and($controller->createdContacts[0][0])->toBe([
            'company_uuid' => 'company-uuid',
            'email'        => 'jane@example.test',
            'type'         => 'customer',
        ])
        ->and($controller->createdOrders[0])->toMatchArray([
            'company_uuid'            => 'company-uuid',
            'order_config_uuid'       => 'order-config-uuid',
            'type'                    => 'transport',
            'payload_uuid'            => 'payload-built-uuid',
            'driver_assigned_uuid'    => 'driver-uuid',
            'vehicle_assigned_uuid'   => 'vehicles-uuid',
            'facilitator_uuid'        => 'contacts-vendors-integrated_vendors-uuid',
            'facilitator_type'        => Contact::class,
            'customer_uuid'           => 'customer-contact-uuid',
            'customer_type'           => Contact::class,
            'status'                  => 'created',
            'adhoc'                   => 1,
            'orchestrator_priority'   => 50,
        ])
        ->and($controller->finalizedOrders)->toBe([
            ['created-order-uuid', 'service-quote-uuid', true, 'company-uuid'],
        ])
        ->and($controller->order->loaded)->toContain(['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);
});

test('api order controller creates orders from top-level route details and reports invalid create inputs', function () {
    $controller = new FleetOpsApiOrderCrudControllerProbe();

    $created = $controller->create(new FleetOpsApiOrderCreateRequestFake([
        'pickup'    => ['name' => 'Pickup'],
        'dropoff'   => ['name' => 'Dropoff'],
        'waypoints' => [['name' => 'Stop']],
        'entities'  => [['name' => 'Box']],
        'customer'  => 'customer_public',
    ]));

    $badConfig                     = new FleetOpsApiOrderCrudControllerProbe();
    $badConfig->missingOrderConfig = true;

    expect($created)->toBe(['resource' => 'order', 'order' => $controller->order])
        ->and($controller->createdOrders[0])->toMatchArray([
            'payload_uuid'  => 'payload-built-uuid',
            'customer_uuid' => 'contacts-vendors-uuid',
            'customer_type' => Contact::class,
        ])
        ->and($badConfig->create(new FleetOpsApiOrderCreateRequestFake([
            'type' => 'missing',
        ])))->toBe(['apiError' => 'Invalid order `type` or `order_config` provided.', 'status' => 400]);
});

test('api order controller updates payloads assignments service quotes and dispatch flags', function () {
    $order          = new FleetOpsApiOrderCrudFake();
    $payload        = new FleetOpsApiOrderPayloadFake();
    $order->payload = $payload;

    $quote = new ServiceQuote();
    $quote->setRawAttributes(['uuid' => 'service-quote-uuid'], true);

    $controller               = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order        = $order;
    $controller->serviceQuote = $quote;

    $response = $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload'               => [
            'waypoints' => [
                ['name' => 'Pickup From Waypoints'],
                ['name' => 'Middle'],
                ['name' => 'Dropoff From Waypoints'],
            ],
            'entities'  => [['name' => 'Crate']],
        ],
        'driver'                => 'driver_public',
        'vehicle'               => 'vehicle_public',
        'facilitator'           => 'facilitator_public',
        'customer'              => 'customer_public',
        'service_quote'         => 'quote_public',
        'dispatch'              => true,
        'orchestrator_priority' => '',
        'status'                => 'ready',
    ]));

    expect($response)->toBe(['resource' => 'order', 'order' => $order])
        ->and($payload->calls)->toContain(
            ['pickup', ['name' => 'Pickup From Waypoints']],
            ['dropoff', ['name'    => 'Dropoff From Waypoints']],
            ['waypoints', [['name' => 'Middle']]],
            ['entities', [['name'  => 'Crate']]]
        )
        ->and($order->purchasedQuotes)->toBe([$quote])
        ->and($order->dispatchedForTest)->toBeTrue()
        ->and($order->updates[0])->toMatchArray([
            'payload_uuid'            => 'payload-built-uuid',
            'driver_assigned_uuid'    => 'drivers-uuid',
            'vehicle_assigned_uuid'   => 'vehicles-uuid',
            'facilitator_uuid'        => 'contacts-vendors-uuid',
            'facilitator_type'        => Contact::class,
            'customer_uuid'           => 'contacts-vendors-uuid',
            'customer_type'           => Contact::class,
            'orchestrator_priority'   => 50,
            'status'                  => 'ready',
        ])
        ->and($order->flushedForTest)->toBeTrue()
        ->and($order->loaded)->toContain(['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);
});

test('api order controller updates by payload id and clears route waypoints', function () {
    $order          = new FleetOpsApiOrderCrudFake();
    $payload        = new FleetOpsApiOrderPayloadFake();
    $order->payload = $payload;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $byId = $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload' => 'payload_public',
    ]));

    $cleared = $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload' => [
            'pickup'    => null,
            'dropoff'   => null,
            'waypoints' => [],
        ],
    ]));

    expect($byId)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->updates[0])->toMatchArray(['payload_uuid' => 'payloads-uuid'])
        ->and($cleared)->toBe(['resource' => 'order', 'order' => $order])
        ->and($payload->removedWaypoints)->toBeTrue();
});

test('api order controller moves the cash to collect onto the payload it switches to by id', function () {
    $order          = new FleetOpsApiOrderCrudFake();
    $order->payload = new FleetOpsApiOrderPayloadFake();
    $switched       = new FleetOpsApiOrderPayloadFake();

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = $order;
    $controller->switchedPayload = $switched;

    $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload'    => 'payload_public',
        'cod_amount' => '1800',
    ]));

    expect($order->updates[0])->toMatchArray(['payload_uuid' => 'payloads-uuid'])
        ->and($switched->getAttributes()['cod_amount'])->toBe(1800)
        ->and($switched->savedForTest)->toBeTrue();
});

test('api order controller finds deletes and updates distance matrices without database records', function () {
    $order              = new FleetOpsApiOrderCrudFake();
    $controller         = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order  = $order;
    $controller->matrix = (object) ['distance' => 2400, 'time' => 720];

    $found    = $controller->find('order-public', new Request());
    $matrix   = $controller->getDistanceMatrix('order-public');
    $deleted  = $controller->delete('order-public', new Request());

    expect($found)->toBe(['resource' => 'order', 'order' => $order])
        ->and($matrix)->toBe(['json' => $controller->matrix, 'status' => 200])
        ->and($order->updates[0])->toBe(['distance' => 2400, 'time' => 720])
        ->and($deleted)->toBe(['resource' => 'deleted-order', 'order' => $order])
        ->and($order->deletedForTest)->toBeTrue()
        ->and($order->lookups[0][0])->toBe('order-public')
        ->and($order->loaded)->toContain(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);
});

test('api order controller schedules orders with timezone normalized dates', function () {
    $order             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $request = new FleetOpsApiOrderScheduleRequestFake([
        'date'     => '2026-08-15',
        'time'     => '09:30:15',
        'timezone' => 'Asia/Singapore',
    ]);

    $response = $controller->scheduleOrder('order-public', $request);

    expect($response)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->savedForTest)->toBeTrue()
        ->and($order->scheduled_at->timezoneName)->toBe('Asia/Singapore')
        ->and($order->scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-08-15 09:30:15');
});

test('api order controller captures signature proof and stores file metadata', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $response = $controller->captureSignature(new Request([
        'signature' => 'data:image/png;base64,' . base64_encode(fleetopsValidTestPng()),
        'remarks'   => 'Signed by receiver',
        'data'      => ['receiver' => 'Ada'],
        'disk'      => 'local',
        'bucket'    => 'local-bucket',
    ]), 'order-public', 'waypoint_subject');

    expect($response['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Signed by receiver',
            'data'         => ['receiver' => 'Ada'],
        ])
        ->and($controller->createdFiles[0])->toMatchArray([
            'company_uuid'  => 'company-uuid',
            'uploader_uuid' => 'user-uuid',
            'bucket'        => 'local-bucket',
            'type'          => 'signature',
        ])
        ->and($response['proof']->file_uuid)->toBe('file-uuid')
        ->and($response['proof']->saved)->toBeTrue()
        ->and($controller->storedFiles[0])->toBe([
            'disk'     => 'local',
            'path'     => 'uploads/company-uuid/signatures/proof_public.png',
            'contents' => fleetopsValidTestPng(),
        ]);
});

test('api order controller captures base64 photo proofs and links stored files', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $response = $controller->capturePhoto(new Request([
        'photos'      => [base64_encode(fleetopsValidTestPng())],
        'remarks'     => 'Photo received',
        'data'        => ['angle' => 'front'],
        'disk'        => 'local',
        'filesystems' => ['disks' => ['local' => ['bucket' => 'local-bucket']]],
    ]), 'order-public', 'waypoint_subject');

    expect($response['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Photo received',
            'raw_data'     => base64_encode(fleetopsValidTestPng()),
            'data'         => ['angle' => 'front'],
        ])
        ->and($controller->createdFiles[0])->toMatchArray([
            'company_uuid'  => 'company-uuid',
            'uploader_uuid' => 'user-uuid',
            'bucket'        => 'local-bucket',
            'type'          => 'photo',
            'file_size'     => strlen(fleetopsValidTestPng()),
        ])
        ->and($response['proof']->updates[0])->toBe(['file_uuid' => 'file-uuid'])
        ->and($controller->storedFiles[0])->toBe([
            'disk'     => 'local',
            'path'     => 'uploads/company-uuid/photos/proof_public.png',
            'contents' => fleetopsValidTestPng(),
        ]);
});

test('api order controller returns proof comment and editable field resources through seams', function () {
    session(['company' => 'company-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                         = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order                  = new FleetOpsApiOrderCrudFake(['order_config_uuid' => 'config-uuid']);
    $controller->resolvedSubject        = $subject;
    $controller->entityEditingSettings  = ['config-uuid' => ['recipient_name' => true]];

    $proofs   = $controller->proofs(new Request(), 'order-public', 'waypoint_subject');
    $settings = $controller->getEditableEntityFields('order-public', new Request());
    $comments = $controller->orderComments('order-public');

    expect($proofs['resource'])->toBe('proofs')
        ->and($proofs['proofs'][0]->subject_uuid)->toBe('subject-uuid')
        ->and($settings)->toBe(['json' => ['recipient_name' => true], 'status' => 200])
        ->and($comments)->toBe(['resource' => 'comments', 'comments' => $controller->order->comments->values()->all()])
        ->and($controller->order->loaded)->toContain('comments');
});

test('api order controller reports proof signature photo and comments error branches', function () {
    $controller = new FleetOpsApiOrderCrudControllerProbe();

    expect($controller->captureSignature(new Request(), 'order-public'))->toBe(['apiError' => 'No signature data to capture.', 'status' => 400]);

    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    expect($controller->captureSignature(new Request(['signature' => base64_encode(fleetopsValidTestPng())]), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->capturePhoto(new Request(['photos' => [base64_encode(fleetopsValidTestPng())]]), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->proofs(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->getEditableEntityFields('missing-order', new Request()))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->orderComments('missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404]);

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = new FleetOpsApiOrderCrudFake();

    expect($controller->captureSignature(new Request(['signature' => base64_encode(fleetopsValidTestPng())]), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to capture signature data.', 'status' => 400])
        ->and($controller->capturePhoto(new Request(['photos' => [base64_encode(fleetopsValidTestPng())]]), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to capture photo as proof.', 'status' => 400])
        ->and($controller->proofs(new Request(), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to retrieve proof of delivery for subject.', 'status' => 400]);
});

test('api order controller dispatches cancels optimizes tracks and estimates orders', function () {
    $order             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $dispatched = $controller->dispatchOrder('order-public');
    $cancelled  = $controller->cancelOrder('order-public');
    $optimized  = $controller->optimize('order-public');
    $tracked    = $controller->trackerData(new Request(['provider' => 'test', 'fallbacks' => true]), 'order-public');
    $eta        = $controller->etaData(new Request(['traffic_enabled' => true]), 'order-public');

    expect($dispatched)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->dispatchedForTest)->toBeTrue()
        ->and($order->dispatchActivityInserted)->toBeTrue()
        ->and($cancelled)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->cancelledForTest)->toBeTrue()
        ->and($optimized)->toBe(['resource' => 'order', 'order' => $order])
        ->and($tracked)->toBe(['json' => ['tracking' => 'ok', 'options' => ['provider' => 'test', 'fallbacks' => true]], 'status' => 200])
        ->and($eta)->toBe(['json' => ['eta' => 600, 'options' => ['traffic_enabled' => true]], 'status' => 200]);
});

test('api order controller reports missing and invalid dispatch tracking branches', function () {
    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    $expectedJson = ['json' => ['error' => 'Order resource not found.'], 'status' => 404];

    expect($controller->find('missing-order', new Request()))->toBe($expectedJson)
        ->and($controller->delete('missing-order', new Request()))->toBe($expectedJson)
        ->and($controller->getDistanceMatrix('missing-order'))->toBe($expectedJson)
        ->and($controller->dispatchOrder('missing-order'))->toBe($expectedJson)
        ->and($controller->cancelOrder('missing-order'))->toBe($expectedJson)
        ->and($controller->optimize('missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->trackerData(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->etaData(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404]);

    $order                           = new FleetOpsApiOrderCrudFake();
    $order->hasDriverAssignedForTest = false;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->dispatchOrder('order-public'))->toBe(['apiError' => 'No driver assigned to dispatch!', 'status' => 400]);

    $order                        = new FleetOpsApiOrderCrudFake();
    $order->dispatchedFlagForTest = true;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->dispatchOrder('order-public'))->toBe(['apiError' => 'Order has already been dispatched!', 'status' => 400]);

    $order                            = new FleetOpsApiOrderCrudFake();
    $order->trackerFake->throwOnTrack = true;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->trackerData(new Request(), 'order-public'))->toBe(['apiError' => 'An error occured trying to track order.', 'status' => 404])
        ->and($controller->etaData(new Request(), 'order-public'))->toBe(['apiError' => 'An error occured trying to track order.', 'status' => 404]);
});

test('api order controller captures QR proof payloads and validates error branches', function () {
    session(['company' => 'company-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $proof = $controller->captureQrScan(new Request([
        'code'     => 'subject-uuid',
        'raw_data' => 'raw-qr-data',
        'data'     => ['scan' => true],
    ]), 'order-public', 'waypoint_subject');

    expect($proof['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Verified by QR Code Scan',
            'raw_data'     => 'raw-qr-data',
            'data'         => ['scan' => true],
        ])
        ->and($controller->captureQrScan(new Request(), 'order-public'))->toBe(['apiError' => 'No QR code data to capture.', 'status' => 400]);

    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    expect($controller->captureQrScan(new Request(['code' => 'subject-uuid']), 'missing-order'))->toBe([
        'apiError' => 'Order resource not found.',
        'status'   => 404,
    ]);

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = new FleetOpsApiOrderCrudFake();

    expect(fleetopsProofErrorResponse($controller->captureQrScan(new Request(['code' => 'subject-uuid']), 'order-public', 'waypoint_missing')))->toBe([
        'apiError' => 'Proof subject is unavailable.',
        'status'   => 409,
    ]);

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    expect(fleetopsProofErrorResponse($controller->captureQrScan(new Request(['code' => 'wrong-code']), 'order-public', 'waypoint_subject')))->toBe([
        'apiError' => 'Unable to validate QR code data.',
        'status'   => 400,
    ]);
});

test('api order controller captures QR proof from tracking url codes and barcodes', function () {
    session(['company' => 'company-uuid']);

    $subject = (object) [
        'uuid'           => 'subject-uuid',
        'public_id'      => 'waypoint_subject',
        'trackingNumber' => (object) ['tracking_number' => 'ACM1234567890SG'],
    ];

    // The url printed by current labels, from any host, and the Code 128 barcode.
    foreach ([
        'https://console.fleetbase.test/track-order?order=ACM1234567890SG&r=waypoint_subject&v=1',
        'https://old-host.example/track-order?order=ACM1234567890SG&r=waypoint_subject&v=1',
        'ACM1234567890SG',
    ] as $code) {
        $controller                  = new FleetOpsApiOrderCrudControllerProbe();
        $controller->order           = new FleetOpsApiOrderCrudFake();
        $controller->resolvedSubject = $subject;

        expect($controller->captureQrScan(new Request(['code' => $code]), 'order-public', 'waypoint_subject')['resource'])->toBe('proof')
            ->and($controller->createdProofs[0]['subject_uuid'])->toBe('subject-uuid');
    }

    // A scanner's whole result object, rather than its value, is refused as before
    // instead of failing the request.
    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    expect($controller->captureQrScan(new Request(['code' => ['type' => 'qr', 'value' => 'ACM1234567890SG']]), 'order-public', 'waypoint_subject'))->toBe([
        'apiError' => 'No QR code data to capture.',
        'status'   => 400,
    ]);

    // Another parcel's label is refused, whether it names another owner or, naming none,
    // another tracking number.
    foreach ([
        'https://console.fleetbase.test/track-order?order=ACM1234567890SG&r=waypoint_other&v=1',
        'https://console.fleetbase.test/track-order?order=ACM0000000000SG&v=1',
    ] as $code) {
        $controller                  = new FleetOpsApiOrderCrudControllerProbe();
        $controller->order           = new FleetOpsApiOrderCrudFake();
        $controller->resolvedSubject = $subject;

        expect(fleetopsProofErrorResponse($controller->captureQrScan(new Request(['code' => $code]), 'order-public', 'waypoint_subject')))->toBe([
            'apiError' => 'Unable to validate QR code data.',
            'status'   => 400,
        ])->and($controller->createdProofs)->toBe([]);
    }
});

test('api order controller refuses order edits the status locks with a 422 and writes nothing', function () {
    $order          = new FleetOpsApiOrderCrudFake(['status' => 'completed']);
    $payload        = new FleetOpsApiOrderPayloadFake();
    $order->payload = $payload;

    $controller                 = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order          = $order;
    $controller->editLockedWith = ['dropoff' => 'The order is completed and can no longer be changed.'];

    $response = $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload' => ['dropoff' => ['name' => 'Somewhere else']],
    ]));

    expect($response)->toBe([
        'apiError' => 'The order is completed and can no longer be changed.',
        'status'   => 422,
        'locked'   => ['dropoff' => 'The order is completed and can no longer be changed.'],
    ])
        ->and($payload->calls)->toBe([])
        ->and($payload->savedForTest)->toBeFalse()
        ->and($order->updates)->toBe([])
        ->and($controller->edit->commits)->toBe(0);
});

test('api order controller passes the edit to the guard, keeps cod 0, replaces entities and commits once', function () {
    $order          = new FleetOpsApiOrderCrudFake(['status' => 'dispatched']);
    $payload        = new FleetOpsApiOrderPayloadFake();
    $order->payload = $payload;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'payload' => [
            'dropoff'       => ['name' => 'New drop'],
            'entities'      => [['id' => 'entity_keep', 'name' => 'Latte']],
            'entities_mode' => 'replace',
            'cod_amount'    => 0,
        ],
        'notes'   => 'Leave at the door',
    ]));

    expect($controller->edit->guarded)->toBe([[
        'input' => [
            'dropoff'    => ['name' => 'New drop'],
            'entities'   => [['id' => 'entity_keep', 'name' => 'Latte']],
            'notes'      => 'Leave at the door',
            'cod_amount' => 0,
        ],
        'entities_mode' => 'replace',
    ]])
        ->and($payload->calls)->toContain(['sync-entities', [['id' => 'entity_keep', 'name' => 'Latte']]])
        ->and(collect($payload->calls)->where(0, 'entities')->all())->toBe([])
        ->and($payload->getAttributes()['cod_amount'])->toBe(0)
        ->and(collect($payload->calls)->where(0, 'current')->all())->toBe([])
        ->and($controller->edit->commits)->toBe(1);
});

test('api order controller reads a top-level cod amount and appends entities by default', function () {
    $order          = new FleetOpsApiOrderCrudFake();
    $payload        = new FleetOpsApiOrderPayloadFake();
    $order->payload = $payload;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $controller->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'entities'   => [['name' => 'Crate']],
        'cod_amount' => '2500',
    ]));

    expect($payload->calls)->toContain(['entities', [['name' => 'Crate']]])
        ->and($payload->getAttributes()['cod_amount'])->toBe(2500)
        ->and($controller->edit->guarded[0]['input'])->toMatchArray(['cod_amount' => '2500'])
        ->and($controller->edit->guarded[0]['entities_mode'])->toBe('append');
});

test('api order controller cancels with a reason when one is given', function () {
    $order             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $controller->cancelOrder('order-public', new Request(['reason' => '  Customer moved away  ']));

    expect($order->cancelledForTest)->toBeTrue()
        ->and($order->cancelReasonForTest)->toBe('Customer moved away');

    $plain             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $plain;
    $controller->cancelOrder('order-public', new Request());

    expect($plain->cancelledForTest)->toBeTrue()
        ->and($plain->cancelReasonForTest)->toBeNull();
});

test('public signature capture rejects malformed, mismatched and terminal inputs before writing proofs', function () {
    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235'];

    session(['company' => 'company-uuid']);
    foreach ([
        ['signature' => ['unexpected' => 'array']],
        ['signature' => 'data:image/png;base64,'],
        ['signature' => '%%%not-base64%%%'],
        ['signature' => base64_encode('not-a-real-image')],
    ] as $payload) {
        expect($controller->captureSignature(new Request($payload), 'order-public'))->toBe([
            'apiError' => 'Invalid signature data.', 'status' => 422,
        ]);
    }

    $valid = new Request(['signature' => base64_encode(fleetopsValidTestPng())]);
    session(['company' => 'other-company']);
    expect($controller->captureSignature($valid, 'order-public'))->toBe([
        'apiError' => 'Proof is not authorized for this company.', 'status' => 403,
    ]);

    session(['company' => 'company-uuid']);
    $controller->order->status = 'completed';
    expect($controller->captureSignature($valid, 'order-public'))->toBe([
        'apiError' => 'Proof capture is closed for this order.', 'status' => 409,
    ]);

    expect($controller->createdProofs)->toBe([])
        ->and($controller->createdFiles)->toBe([])
        ->and($controller->storedFiles)->toBe([]);
});

test('public proof capture rejects missing payloads for explicit waypoint subjects', function () {
    session(['company' => 'company-uuid']);
    $controller                        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order                 = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject       = new Waypoint();
    $controller->resolvedSubject->uuid = 'waypoint-r235';

    $response = $controller->captureSignature(new Request([
        'signature' => base64_encode(fleetopsValidTestPng()),
    ]), 'order-public', 'waypoint_r235');

    expect(fleetopsProofErrorResponse($response))->toBe([
        'apiError' => 'Proof requires an active order payload.', 'status' => 409,
    ]);
    expect($controller->createdProofs)->toBe([])
        ->and($controller->createdFiles)->toBe([]);
});

test('public proof capture fails closed when fresh authorization snapshot cannot be established', function () {
    session(['company' => 'company-uuid']);
    $controller = new class extends FleetOpsApiOrderCrudControllerProbe {
        protected function requireFreshProofAuthorizationSnapshot(): void
        {
            throw new RuntimeException('Simulated post-lock auth snapshot failure.');
        }
    };
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235'];

    $png = base64_encode(fleetopsValidTestPng());
    expect($controller->captureQrScan(new Request(['code' => 'valid-looking-code']), 'order-public'))->toBe([
        'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
    ])
        ->and($controller->captureSignature(new Request(['signature' => $png, 'disk' => 'local']), 'order-public'))->toBe([
            'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
        ])
        ->and($controller->capturePhoto(new Request(['photos' => [$png], 'disk' => 'local']), 'order-public'))->toBe([
            'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
        ])
        ->and($controller->createdProofs)->toBe([])
        ->and($controller->createdFiles)->toBe([])
        ->and($controller->storedFiles)->toBe([]);
});

class R235ProofPreflightRequest extends Request
{
    public function validate(array $rules, ...$arguments): array
    {
        // Skip Laravel validation to test the controller's independent fail-closed checks.
        return [];
    }
}

class R235ProofBatchBudgetProbe extends FleetOpsApiOrderCrudControllerProbe
{
    protected function inspectIncomingProofImage(Illuminate\Http\UploadedFile|string $photo): ?array
    {
        // The input-validation seam simulates three individually permitted
        // 9 MB images: the sum must still be rejected before any write.
        return ['size' => 9 * 1024 * 1024, 'contents' => 'synthetic', 'extension' => 'png', 'content_type' => 'image/png'];
    }
}

test('public photo capture independently rejects empty, malformed and oversized batches', function () {
    $controller = new FleetOpsApiOrderCrudControllerProbe();

    expect($controller->capturePhoto(new R235ProofPreflightRequest(['photos' => []]), 'order-public'))->toBe([
        'apiError' => 'No photo data to capture.', 'status' => 400,
    ]);
    expect($controller->capturePhoto(new R235ProofPreflightRequest([
        'photos' => array_fill(0, 11, 'not-a-valid-image'),
    ]), 'order-public'))->toBe([
        'apiError' => 'Photo batch contains invalid inputs.', 'status' => 422,
    ]);
    expect($controller->capturePhoto(new R235ProofPreflightRequest([
        'photos' => ['image-text', ['unexpected' => 'nonstring']],
    ]), 'order-public'))->toBe([
        'apiError' => 'Photo batch contains invalid inputs.', 'status' => 422,
    ]);
    expect($controller->capturePhoto(new R235ProofPreflightRequest([
        'photos' => ['bad-png'],
    ]), 'order-public'))->toBe([
        'apiError' => 'Photo batch contains an invalid image.', 'status' => 422,
    ]);

    $budgetProbe = new R235ProofBatchBudgetProbe();
    expect($budgetProbe->capturePhoto(new R235ProofPreflightRequest([
        'photos' => ['synthetic-image-1', 'synthetic-image-2', 'synthetic-image-3'],
    ]), 'order-public'))->toBe([
        'apiError' => 'Photo batch must contain at most 20 MB of images.', 'status' => 422,
    ]);
    expect($controller->createdProofs)->toBe([])
        ->and($budgetProbe->createdProofs)->toBe([])
        ->and($budgetProbe->createdFiles)->toBe([]);
});

test('public photo capture refuses cross-tenant and terminal order writes before storage', function () {
    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235-photo'];
    $request                     = new Request([
        'photos' => [base64_encode(fleetopsValidTestPng())],
        'disk'   => 'local',
    ]);

    session(['company' => 'other-company']);
    expect($controller->capturePhoto($request, 'order-public'))->toBe([
        'apiError' => 'Proof is not authorized for this company.', 'status' => 403,
    ]);

    session(['company' => 'company-uuid']);
    $controller->order->status = 'canceled';
    expect($controller->capturePhoto($request, 'order-public'))->toBe([
        'apiError' => 'Proof capture is closed for this order.', 'status' => 409,
    ]);
    expect($controller->createdProofs)->toBe([])
        ->and($controller->storedFiles)->toBe([]);
});

class R235ProofDiskFailureProbe extends FleetOpsApiOrderCrudControllerProbe
{
    public array $cleanups = [];

    protected function putStorage(string $disk, string $path, string $contents): void
    {
        throw new RuntimeException('Simulated signature storage failure.');
    }

    protected function storeProofPhoto(Proof $proof, Illuminate\Http\UploadedFile|string $photo, string $disk, string $bucket): File
    {
        throw new RuntimeException('Simulated photo storage failure.');
    }

    protected function cleanupIncompleteProofFiles(string $disk, array $storedPaths): void
    {
        $this->cleanups[] = [$disk, $storedPaths];
    }
}

test('public signature and photo capture compensate storage failures without returning proof resources', function () {
    session(['company' => 'company-uuid']);
    $controller                  = new R235ProofDiskFailureProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235-storage'];

    $png = base64_encode(fleetopsValidTestPng());
    expect($controller->captureSignature(new Request(['signature' => $png, 'disk' => 'local']), 'order-public'))->toBe([
        'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
    ])
        ->and($controller->capturePhoto(new Request(['photos' => [$png], 'disk' => 'local']), 'order-public'))->toBe([
            'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
        ])
        ->and($controller->cleanups)->toBe([
            ['local', ['uploads/company-uuid/signatures/proof_public.png']],
            ['local', ['uploads/company-uuid/photos/proof_public.png']],
        ])
        ->and($controller->createdFiles)->toBe([]);
});

class R235ProofPostLockFailureProbe extends FleetOpsApiOrderCrudControllerProbe
{
    public array $cleanups = [];

    protected function lockProofCaptureSubject(Order $order, ?string $type, ?string $subjectId): array
    {
        throw new Illuminate\Http\Exceptions\HttpResponseException(new Illuminate\Http\JsonResponse(['error' => 'Proof subject changed during lock wait.'], 409));
    }

    protected function cleanupIncompleteProofFiles(string $disk, array $storedPaths): void
    {
        $this->cleanups[] = [$disk, $storedPaths];
    }
}

test('public proof capture denies post-lock subject changes with no SQL proof creation', function () {
    session(['company' => 'company-uuid']);
    $controller                  = new R235ProofPostLockFailureProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235-lock'];

    $png = base64_encode(fleetopsValidTestPng());
    expect(fleetopsProofErrorResponse($controller->captureSignature(new Request([
        'signature' => $png, 'disk' => 'local',
    ]), 'order-public')))->toBe([
        'apiError' => 'Proof subject changed during lock wait.', 'status' => 409,
    ])
        ->and(fleetopsProofErrorResponse($controller->capturePhoto(new Request([
            'photos' => [$png], 'disk' => 'local',
        ]), 'order-public')))->toBe([
            'apiError' => 'Proof subject changed during lock wait.', 'status' => 409,
        ])
        ->and($controller->createdProofs)->toBe([])
        ->and($controller->createdFiles)->toBe([])
        ->and($controller->cleanups)->toBe([['local', []], ['local', []]]);
});

class R235ProofCleanupProbe extends FleetOpsApiOrderCrudControllerProbe
{
    public function cleanPaths(array $paths): void
    {
        $this->cleanupIncompleteProofFiles('synthetic-proof-disk', $paths);
    }
}

test('proof cleanup deletes files in reverse order and continues after a storage deletion error', function () {
    $storage = new class {
        public array $deletes = [];

        public function disk($disk): self
        {
            expect($disk)->toBe('synthetic-proof-disk');

            return $this;
        }

        public function delete(string $path): bool
        {
            $this->deletes[] = $path;
            if ($path === 'middle.png') {
                throw new RuntimeException('Synthetic storage delete failure.');
            }

            return true;
        }
    };
    Illuminate\Support\Facades\Storage::swap($storage);
    (new R235ProofCleanupProbe())->cleanPaths(['first.png', 'middle.png', 'last.png']);

    expect($storage->deletes)->toBe(['last.png', 'middle.png', 'first.png']);
});

class R235ProofStoreHelperProbe extends FleetOpsApiOrderCrudControllerProbe
{
    public function storeImageForTest(Proof $proof, Illuminate\Http\UploadedFile|string $image): File
    {
        return $this->storeProofPhoto($proof, $image, 'local', 'test-bucket');
    }
}

test('proof storage helper rejects invalid image bytes before creating files', function () {
    $controller = new R235ProofStoreHelperProbe();
    $proof      = new FleetOpsApiOrderProofFake();
    $proof->setRawAttributes(['uuid' => 'proof-r235', 'public_id' => 'proof-r235'], true);

    expect(fn () => $controller->storeImageForTest($proof, '%%%invalid-base64%%%'))
        ->toThrow(InvalidArgumentException::class, 'Invalid photo image payload.');
    expect($controller->createdFiles)->toBe([])
        ->and($controller->storedFiles)->toBe([]);
});

test('public proof capture sets isolation on the next transaction only and rejects nesting', function () {
    $database = new class {
        public int $depth        = 1;
        public array $statements = [];

        public function transactionLevel(): int
        {
            return $this->depth;
        }

        public function statement(string $sql): bool
        {
            $this->statements[] = $sql;

            return true;
        }
    };

    $previousDb = Illuminate\Support\Facades\DB::getFacadeRoot();
    try {
        Illuminate\Support\Facades\DB::swap($database);
        $method = new ReflectionMethod(OrderController::class, 'requireFreshProofAuthorizationSnapshot');
        $method->setAccessible(true);
        $controller = new OrderController();

        expect(fn () => $method->invoke($controller))
            ->toThrow(RuntimeException::class, 'Proof capture requires a top-level database transaction.')
            ->and($database->statements)->toBe([]);

        $database->depth = 0;
        $method->invoke($controller);
        expect($database->statements)->toBe(['SET TRANSACTION ISOLATION LEVEL READ COMMITTED']);
    } finally {
        if ($previousDb !== null) {
            Illuminate\Support\Facades\DB::swap($previousDb);
        } else {
            Illuminate\Support\Facades\DB::clearResolvedInstance('db');
        }
    }
});

test('public order create and update reject a foreign or missing quote before mutation', function () {
    $create               = new FleetOpsApiOrderCrudControllerProbe();
    $create->serviceQuote = null;
    $request              = new FleetOpsApiOrderCreateRequestFake([
        'type'          => 'transport',
        'service_quote' => 'quote_from_another_tenant',
    ]);

    $created = $create->create($request);
    expect($created)->toBeInstanceOf(Illuminate\Http\JsonResponse::class)
        ->and($created->getStatusCode())->toBe(422)
        ->and($created->getData(true))->toBe(['error' => 'Invalid service quote for this company.'])
        ->and($create->createdOrders)->toBe([])
        ->and($create->finalizedOrders)->toBe([]);

    $update               = new FleetOpsApiOrderCrudControllerProbe();
    $update->order        = new FleetOpsApiOrderCrudFake();
    $update->serviceQuote = null;
    $result               = $update->update('order-public', new FleetOpsApiOrderUpdateRequestFake([
        'service_quote' => 'quote_from_another_tenant',
        'notes'         => 'must never be persisted',
    ]));
    expect($result)->toBeInstanceOf(Illuminate\Http\JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422)
        ->and($result->getData(true))->toBe(['error' => 'Invalid service quote for this company.'])
        ->and($update->order->updates)->toBe([]);
});

test('proof subject resolver refuses empty subjects and allows only matching order identity', function () {
    $subject = new Order();
    $subject->setRawAttributes([
        'uuid'         => 'order-uuid',
        'public_id'    => 'order_public',
        'company_uuid' => 'tenant-a',
        'payload_uuid' => 'payload-uuid',
    ], true);
    $controller = new OrderController();
    $method     = new ReflectionMethod(OrderController::class, 'resolveSubject');
    $method->setAccessible(true);
    expect($method->invoke($controller, $subject, 'order', ''))->toBeNull()
        ->and($method->invoke($controller, $subject, 'order', 'order-uuid'))->toBe($subject)
        ->and($method->invoke($controller, $subject, 'order', 'order_public'))->toBe($subject)
        ->and($method->invoke($controller, $subject, 'order', 'order_other_tenant'))->toBeNull();
});

test('proof image boundary rejects an empty base64 body and an invalid file upload', function () {
    $controller = new OrderController();
    $method     = new ReflectionMethod(OrderController::class, 'inspectIncomingProofImage');
    $method->setAccessible(true);

    $path = tempnam(sys_get_temp_dir(), 'r235-invalid-proof-');
    try {
        file_put_contents($path, 'not a valid uploaded image');
        $untrusted = new Illuminate\Http\UploadedFile($path, 'invalid.png', 'image/png', null, false);
        expect($method->invoke($controller, ''))->toBeNull()
            ->and($method->invoke($controller, $untrusted))->toBeNull();
    } finally {
        unlink($path);
    }
});

class R235PhotoPayloadChangesAfterPreflightProbe extends FleetOpsApiOrderCrudControllerProbe
{
    public int $inspections = 0;

    protected function inspectIncomingProofImage(Illuminate\Http\UploadedFile|string $photo): ?array
    {
        $this->inspections++;

        return $this->inspections === 1 ? parent::inspectIncomingProofImage($photo) : null;
    }
}

test('photo capture refuses a payload that becomes invalid after preflight before any persistence', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $controller                  = new R235PhotoPayloadChangesAfterPreflightProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = (object) ['uuid' => 'subject-r235-race'];
    $png                         = base64_encode(fleetopsValidTestPng());

    $response = $controller->capturePhoto(new R235ProofPreflightRequest([
        'photos' => [$png], 'disk' => 'local',
    ]), 'order-public');

    expect($response)->toBe([
        'apiError' => 'Unable to store proof of delivery.', 'status' => 503,
    ])->and($controller->inspections)->toBe(2)
        ->and($controller->createdProofs)->toBe([])
        ->and($controller->createdFiles)->toBe([])
        ->and($controller->storedFiles)->toBe([]);
});
