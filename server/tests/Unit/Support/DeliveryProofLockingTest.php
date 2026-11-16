<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Http\Exceptions\HttpResponseException;

class R235DeliveryProofLockingProbe extends OrderController
{
    public ?Order $lockedOrder        = null;
    public mixed $resolvedSubject     = null;
    public array $authorizationChecks = [];
    public array $lockLookups         = [];

    public function lockForTest(Order $original, ?string $type, ?string $subjectId): array
    {
        return $this->lockProofCaptureSubject($original, $type, $subjectId);
    }

    public function authorizeSubjectForTest(Order $order, mixed $subject): void
    {
        $this->assertProofCaptureAllowed($order, $subject);
    }

    protected function findOrder(string $id, array $with = [], array $withCount = [], bool $lockForUpdate = false): Order
    {
        $this->lockLookups[] = [$id, $lockForUpdate];

        return $this->lockedOrder;
    }

    protected function authorizeLockedProofCapture(Order $order): void
    {
        $this->authorizationChecks[] = $order->uuid;
    }

    protected function resolveSubject(Order $order, ?string $type, ?string $subjectId = null)
    {
        return $this->resolvedSubject;
    }
}

function r235ProofLockingOrder(string $uuid = 'order-a', string $company = 'tenant-a', string $status = 'created'): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'         => $uuid,
        'public_id'    => 'order_public',
        'company_uuid' => $company,
        'status'       => $status,
        'payload_uuid' => null,
    ], true);
    $order->setRelation('payload', null);

    return $order;
}

function r235ProofLockingError(callable $action): int
{
    try {
        $action();

        return 200;
    } catch (HttpResponseException $exception) {
        return $exception->getResponse()->getStatusCode();
    }
}

test('proof row lock rejects changed tenant or order identity before post-lock authorization', function (string $company, string $uuid) {
    session(['company' => 'tenant-a']);
    $original               = r235ProofLockingOrder();
    $probe                  = new R235DeliveryProofLockingProbe();
    $probe->lockedOrder     = r235ProofLockingOrder($uuid, $company);
    $probe->resolvedSubject = $probe->lockedOrder;

    expect(r235ProofLockingError(fn () => $probe->lockForTest($original, null, null)))->toBe(403)
        ->and($probe->authorizationChecks)->toBe([])
        ->and($probe->lockLookups)->toBe([['order_public', true]]);
})->with([
    ['tenant-b', 'order-a'],
    ['tenant-a', 'order-replaced'],
]);

test('proof row lock rejects absent target subject after reauthorizing the locked order', function () {
    session(['company' => 'tenant-a']);
    $original           = r235ProofLockingOrder();
    $probe              = new R235DeliveryProofLockingProbe();
    $probe->lockedOrder = r235ProofLockingOrder();

    expect(r235ProofLockingError(fn () => $probe->lockForTest($original, 'waypoint', 'missing')))->toBe(409)
        ->and($probe->authorizationChecks)->toBe(['order-a']);
});

test('proof row lock permits only the current nonterminal order and reauthorizes after locking', function () {
    session(['company' => 'tenant-a']);
    $original               = r235ProofLockingOrder();
    $probe                  = new R235DeliveryProofLockingProbe();
    $probe->lockedOrder     = r235ProofLockingOrder();
    $probe->resolvedSubject = $probe->lockedOrder;

    [$order, $subject] = $probe->lockForTest($original, null, null);

    expect($order)->toBe($probe->lockedOrder)
        ->and($subject)->toBe($probe->lockedOrder)
        ->and($probe->authorizationChecks)->toBe(['order-a']);
});

test('proof row lock rejects a completed or canceled order after authorization', function (string $status) {
    session(['company' => 'tenant-a']);
    $original               = r235ProofLockingOrder();
    $probe                  = new R235DeliveryProofLockingProbe();
    $probe->lockedOrder     = r235ProofLockingOrder('order-a', 'tenant-a', $status);
    $probe->resolvedSubject = $probe->lockedOrder;

    expect(r235ProofLockingError(fn () => $probe->lockForTest($original, null, null)))->toBe(409)
        ->and($probe->authorizationChecks)->toBe(['order-a']);
})->with(['completed', 'canceled']);

test('proof capture requires an active payload for an explicit waypoint subject', function () {
    session(['company' => 'tenant-a']);
    $probe    = new R235DeliveryProofLockingProbe();
    $order    = r235ProofLockingOrder();
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-a'], true);
    $probe->lockedOrder     = $order;
    $probe->resolvedSubject = $waypoint;

    expect(r235ProofLockingError(fn () => $probe->lockForTest($order, 'waypoint', 'waypoint-a')))->toBe(409)
        ->and($probe->authorizationChecks)->toBe(['order-a']);
});

test('native locked proof authorization delegates the actual locked order and request', function () {
    $locked  = r235ProofLockingOrder();
    $request = Illuminate\Http\Request::create('/proofs', 'POST');
    app()->instance('request', $request);
    $spy = new class extends Fleetbase\FleetOps\Support\DeliveryProofAuthorization {
        public array $seen = [];

        public function authorize(Order $order, Illuminate\Http\Request $request): void
        {
            $this->seen[] = [$order, $request];
        }
    };
    app()->instance(Fleetbase\FleetOps\Support\DeliveryProofAuthorization::class, $spy);

    $method = new ReflectionMethod(OrderController::class, 'authorizeLockedProofCapture');
    $method->setAccessible(true);
    $method->invoke(new OrderController(), $locked);

    expect($spy->seen)->toBe([[$locked, $request]]);
});

test('native proof guard forbids completed and canceled orders before recording proof', function (string $status) {
    $locked = r235ProofLockingOrder('order-a', 'tenant-a', $status);
    $method = new ReflectionMethod(OrderController::class, 'assertProofCaptureAllowed');
    $method->setAccessible(true);

    expect(r235ProofLockingError(fn () => $method->invoke(new OrderController(), $locked, $locked)))->toBe(409);
})->with(['completed', 'canceled']);

test('native proof guard refuses a waypoint without an active payload', function () {
    $locked   = r235ProofLockingOrder();
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-a'], true);
    $method = new ReflectionMethod(OrderController::class, 'assertProofCaptureAllowed');
    $method->setAccessible(true);

    expect(r235ProofLockingError(fn () => $method->invoke(new OrderController(), $locked, $waypoint)))->toBe(409);
});

test('native proof guard accepts an order with payload but no per-stop activity', function () {
    $locked  = r235ProofLockingOrder();
    $payload = new Fleetbase\FleetOps\Models\Payload();
    $payload->setRawAttributes(['uuid' => 'payload-a'], true);
    $locked->setRelation('payload', $payload);
    $controller = new class extends OrderController {
        protected function payloadUsesServiceStopActivity(?Fleetbase\FleetOps\Models\Payload $payload): bool
        {
            return false;
        }
    };
    $method = new ReflectionMethod(OrderController::class, 'assertProofCaptureAllowed');
    $method->setAccessible(true);

    expect(r235ProofLockingError(fn () => $method->invoke($controller, $locked, $locked)))->toBe(200);
});
