<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Exceptions\OrderEditLockedException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\OrderEdit;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\FleetOps\Support\TrackingCode;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Comment as CommentResource;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ResolvesOrderServiceStops;

    /**
     * Creates a new Fleetbase Order resource.
     *
     * @param Request|CreateOrderRequest $request
     *
     * @return \Fleetbase\Http\Resources\Order
     */
    public function create(CreateOrderRequest $request)
    {
        $idempotencyKey = $this->idempotencyKeyFromRequest($request);
        if ($idempotencyKey === null) {
            return $this->createOrderFromRequest($request);
        }

        // An `Idempotency-Key` makes retries safe: the first request creates the order, and any
        // repeat with the same key (same company and credential) within a day returns that order
        // instead of creating another. A concurrent duplicate is refused while the first runs.
        $cacheKey = $this->orderIdempotencyCacheKey($idempotencyKey);
        if ($replayed = $this->replayIdempotentOrder($cacheKey, $request)) {
            return $replayed;
        }

        $lock = Cache::lock($cacheKey . ':lock', 180);
        if (!$lock->get()) {
            return response()->json(['errors' => ['A request with this Idempotency-Key is already being processed.']], 409);
        }

        try {
            if ($replayed = $this->replayIdempotentOrder($cacheKey, $request)) {
                return $replayed;
            }

            $response = $this->createOrderFromRequest($request);
            if ($response instanceof OrderResource && $response->resource instanceof Order && $response->resource->public_id) {
                Cache::put($cacheKey, $response->resource->public_id, now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    protected function idempotencyKeyFromRequest(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key !== '' && strlen($key) <= 255 ? $key : null;
    }

    protected function orderIdempotencyCacheKey(string $idempotencyKey): string
    {
        return 'fleetops:order-create:' . sha1(implode('|', [(string) $this->sessionCompany(), (string) session('api_credential'), $idempotencyKey]));
    }

    protected function replayIdempotentOrder(string $cacheKey, Request $request)
    {
        $publicId = Cache::get($cacheKey);
        if (!is_string($publicId) || $publicId === '') {
            return null;
        }

        $order = Order::where('public_id', $publicId)->where('company_uuid', $this->sessionCompany())->first();
        if (!$order) {
            return null;
        }

        return $this->orderResource($order)->response($request)->header('Idempotent-Replayed', 'true');
    }

    /**
     * Creates a Fleetbase Order resource from the request (the body of create()).
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    protected function createOrderFromRequest(CreateOrderRequest $request)
    {
        set_time_limit(180);

        // get request input
        $input = $this->orderCreateInputFromRequest($request);

        // Get order config
        $orderConfig = $this->resolveOrderConfig($request->only(['type', 'order_config']));
        if (!$orderConfig) {
            return $this->apiError('Invalid order `type` or `order_config` provided.');
        }

        // Set order config to input
        $input['order_config_uuid'] = $orderConfig->uuid;
        $input['type']              = $orderConfig->key ?? 'transport';

        // make sure company is set
        $input['company_uuid'] = $this->sessionCompany();

        // Validate an explicitly supplied quote before creating payloads/orders.
        $serviceQuote          = $this->resolveServiceQuote($request);
        if (ServiceQuote::hasReferenceInRequest($request) && !$serviceQuote) {
            return response()->apiError('Invalid service quote for this company.', 422);
        }
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            // create order with integrated vendor, then resume fleetbase order creation
            try {
                $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
            } catch (\Exception $e) {
                return response()->apiError($e->getMessage());
            }
        }

        // create payload
        if ($request->has('payload') && $request->isArray('payload')) {
            $payload                = $this->newPayload();
            [
                'entities'  => $entities,
                'waypoints' => $waypoints,
                'pickup'    => $pickup,
                'dropoff'   => $dropoff,
                'return'    => $return,
            ] = $this->payloadShapeFromArray($request->input('payload'));

            if ($pickup) {
                $payload->setPickup($pickup, [
                    'callback' => function ($pickup, $payload) {
                        $payload->setCurrentWaypoint($pickup);
                    },
                ]);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            $payload->setWaypoints($waypoints);
            $payload->setEntities($entities);

            // set the first / current waypoint
            $firstWaypoint = $payload->getPickupOrFirstWaypoint();
            if ($firstWaypoint instanceof Place) {
                $payload->setCurrentWaypoint($firstWaypoint);
            }

            $input['payload_uuid'] = $payload->uuid;
        } elseif ($request->isString('payload')) {
            $input['payload_uuid'] = $this->getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => $this->sessionCompany(),
            ]);
            unset($input['payload']);
        }

        // create a payload if missing payload[] but has pickup/dropoff/etc
        if ($request->missing('payload')) {
            $payload      = $this->newPayload();
            [
                'entities'  => $entities,
                'waypoints' => $waypoints,
                'pickup'    => $pickup,
                'dropoff'   => $dropoff,
                'return'    => $return,
            ] = $this->payloadShapeFromRequest($request);

            if ($pickup) {
                $payload->setPickup($pickup, [
                    'callback' => function ($pickup, $payload) {
                        $payload->setCurrentWaypoint($pickup);
                    },
                ]);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            $payload->setWaypoints($waypoints);
            $payload->setEntities($entities);

            $input['payload_uuid'] = $payload->uuid;

            // set the first / current waypoint
            $firstWaypoint = $payload->getPickupOrFirstWaypoint();
            if ($firstWaypoint instanceof Place) {
                $payload->setCurrentWaypoint($firstWaypoint);
            }
        }

        // driver assignment
        if ($request->has('driver') && $integratedVendorOrder === null) {
            $driver = $this->findDriverByPublicId($request->input('driver'));
            if ($driver) {
                $input['driver_assigned_uuid'] = $driver->uuid;
                // set vehicle assignmend from driver
                if ($driver->vehicle_uuid) {
                    $input['vehicle_assigned_uuid'] = $driver->vehicle_uuid;
                }
            }
        }

        // driver assignment
        if ($request->has('vehicle') && $integratedVendorOrder === null) {
            $input['vehicle_assigned_uuid'] = $this->getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // facilitator assignment
        if ($request->has('facilitator') && $integratedVendorOrder === null) {
            $facilitator = $this->getUuid(
                ['contacts', 'vendors', 'integrated_vendors'],
                [
                    'public_id'    => $request->input('facilitator'),
                    'company_uuid' => $this->sessionCompany(),
                ],
                [
                    'with_table' => true,
                ]
            );

            if (is_array($facilitator)) {
                $input['facilitator_uuid'] = Utils::get($facilitator, 'uuid');
                $input['facilitator_type'] = $this->getModelClassName(Utils::get($facilitator, 'table'));
            }
        } elseif ($integratedVendorOrder) {
            $input['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
            $input['facilitator_type'] = $this->getModelClassName('integrated_vendors');
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = $request->input('customer');

            if (is_string($customer)) {
                $customer = $this->getUuid(
                    ['contacts', 'vendors'],
                    [
                        'public_id'    => $customer,
                        'company_uuid' => $this->sessionCompany(),
                    ],
                    [
                        'with_table' => true,
                    ]
                );

                if (is_array($customer)) {
                    $input['customer_uuid'] = Utils::get($customer, 'uuid');
                    $input['customer_type'] = $this->getModelClassName(Utils::get($customer, 'table'));
                }
            } elseif (is_array($customer)) {
                // create customer from input
                $customer = Arr::only($customer, ['internal_id', 'name', 'title', 'email', 'phone', 'meta']);

                try {
                    $customerCandidate = $this->newCustomerContact([
                        ...$customer,
                        'company_uuid' => $this->sessionCompany(),
                        'type'         => 'customer',
                    ]);
                    $customerCandidate->assertCustomerIdentityIsAvailable();

                    $customer = $this->firstOrCreateCustomerContact(
                        [
                            'company_uuid' => $this->sessionCompany(),
                            'email'        => $customer['email'],
                            'type'         => 'customer',
                        ],
                        [
                            ...$customer,
                            'company_uuid' => $this->sessionCompany(),
                            'type'         => 'customer',
                        ]
                    );
                } catch (CustomerUserConflictException $e) {
                    return response()->apiError($e->getMessage());
                } catch (UserAlreadyExistsException $e) {
                    return response()->apiError($e->getMessage());
                } catch (\Exception $e) {
                    return response()->apiError('Failed to find or create customer for order.');
                }

                if ($customer instanceof Contact) {
                    $input['customer_uuid'] = $customer->uuid;
                    $input['customer_type'] = $this->getModelClassName($customer);
                }
            }
        }

        // if no status is set its default to `created`
        if (!isset($input['status'])) {
            $input['status'] = 'created';
        }

        // if adhoc set convert to sql ready boolean value 1 or 0
        if (isset($input['adhoc']) && $integratedVendorOrder === null) {
            $input['adhoc'] = Utils::isTrue($input['adhoc']) ? 1 : 0;
        }

        // Ensure orchestrator_priority is never null — the column is NOT NULL
        // and the DB default is bypassed when Eloquent receives an explicit null.
        if (!isset($input['orchestrator_priority']) || !is_numeric($input['orchestrator_priority'])) {
            $input['orchestrator_priority'] = 50;
        }

        if (!isset($input['payload_uuid'])) {
            return response()->apiError('Attempted to attach invalid payload to order.');
        }

        // create the order
        $order = $this->createOrder($input);

        // if it's integrated vendor order apply to meta
        if ($integratedVendorOrder) {
            $order->updateMeta([
                'integrated_vendor'       => $serviceQuote->integratedVendor->public_id,
                'integrated_vendor_order' => $integratedVendorOrder,
            ]);
        }

        // load required relations
        $order->load(['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);

        // Determine if order should be dispatched on creation
        $shouldDispatch = $request->boolean('dispatch') && $integratedVendorOrder === null;

        $this->dispatchFinalizeApiOrderCreation(
            $order->uuid,
            $serviceQuote instanceof ServiceQuote ? $serviceQuote->uuid : null,
            $shouldDispatch,
            $order->company_uuid
        );

        // response the driver resource
        return $this->orderResource($order);
    }

    /**
     * Updates a Fleetbase Order resource.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\Order
     */
    public function update($id, UpdateOrderRequest $request)
    {
        set_time_limit(180);

        // find for the order
        try {
            $order = $this->findOrder($id, ['trackingNumber', 'driverAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // Reject invalid/foreign quote references before any order mutation.
        if (ServiceQuote::hasReferenceInRequest($request) && !$this->resolveServiceQuote($request)) {
            return response()->apiError('Invalid service quote for this company.', 422);
        }

        // get request input
        $input = $this->orderUpdateInputFromRequest($request);

        // `entities_mode=replace` makes the entities sent the payload's full list
        // (update by id, create new, delete missing); by default they are appended
        $entitiesMode = OrderEdit::entitiesMode($request->input('entities_mode', $request->input('payload.entities_mode')));
        $codAmount    = $this->codAmountFromRequest($request);

        // refuse changes the order's status locks before anything is written
        $edit = $this->beginOrderEdit($order);
        try {
            $edit->guard($edit->requestedChanges($this->orderEditInputFromRequest($request, $codAmount), $entitiesMode));
        } catch (OrderEditLockedException $exception) {
            return $this->lockedEditResponse($exception);
        }

        // update payload if new input or change payload by id
        if ($request->isArray('payload')) {
            $payload = data_get($order, 'payload', $this->newPayload());
            [
                'entities'                  => $entities,
                'waypoints'                 => $waypoints,
                'pickup'                    => $pickup,
                'dropoff'                   => $dropoff,
                'return'                    => $return,
                'has_waypoints_field'       => $hasWaypointsField,
                'has_route_endpoint_fields' => $hasRouteEndpointFields,
            ] = $this->payloadShapeFromArray($request->input('payload'));

            // if no pickup and dropoff extract from waypoints
            if (empty($pickup) && empty($dropoff) && count($waypoints)) {
                $pickup  = array_shift($waypoints);
                $dropoff = array_pop($waypoints);
            }

            if ($pickup) {
                $payload->setPickup($pickup);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            if ($codAmount !== null) {
                $payload->cod_amount = $codAmount;
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            if ($hasWaypointsField && is_array($waypoints) && count($waypoints)) {
                $payload->setWaypoints($waypoints);
            } elseif ($hasWaypointsField || $hasRouteEndpointFields) {
                $payload->removeWaypoints();
            }

            $this->applyPayloadEntities($payload, $entities, $entitiesMode);

            // the current waypoint is reconciled once the edit is committed: it is only
            // moved when the route changed, and an order under way keeps its stop

            $input['payload_uuid'] = $payload->uuid;
        } elseif ($request->has('payload')) {
            $input['payload_uuid'] = $this->getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => $this->sessionCompany(),
            ]);
            unset($input['payload']);

            if ($codAmount !== null && is_string($input['payload_uuid']) && ($switchedPayload = $this->findPayloadByUuid($input['payload_uuid']))) {
                $switchedPayload->cod_amount = $codAmount;
                $switchedPayload->save();
            }
        }

        // create a payload if missing payload[] but has pickup/dropoff/etc
        if ($request->missing('payload')) {
            $payload = data_get($order, 'payload', $this->newPayload());
            [
                'entities'                  => $entities,
                'waypoints'                 => $waypoints,
                'pickup'                    => $pickup,
                'dropoff'                   => $dropoff,
                'return'                    => $return,
                'has_waypoints_field'       => $hasWaypointsField,
                'has_route_endpoint_fields' => $hasRouteEndpointFields,
            ] = $this->payloadShapeFromRequest($request);

            // if no pickup and dropoff extract from waypoints
            if (empty($pickup) && empty($dropoff) && count($waypoints)) {
                $pickup  = array_shift($waypoints);
                $dropoff = array_pop($waypoints);
            }

            if ($pickup) {
                $payload->setPickup($pickup);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            if ($codAmount !== null) {
                $payload->cod_amount = $codAmount;
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            if ($hasWaypointsField && is_array($waypoints) && count($waypoints)) {
                $payload->setWaypoints($waypoints);
            } elseif ($hasWaypointsField || $hasRouteEndpointFields) {
                $payload->removeWaypoints();
            }

            $this->applyPayloadEntities($payload, $entities, $entitiesMode);

            // the current waypoint is reconciled once the edit is committed: it is only
            // moved when the route changed, and an order under way keeps its stop

            $input['payload_uuid'] = $payload->uuid;
        }

        // driver assignment
        if ($request->has('driver')) {
            $input['driver_assigned_uuid'] = $this->getUuid('drivers', [
                'public_id'    => $request->input('driver'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // vehicle assignment
        if ($request->has('vehicle')) {
            $input['vehicle_assigned_uuid'] = $this->getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // facilitator assignment
        if ($request->has('facilitator')) {
            $facilitator = $this->getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('facilitator'),
                    'company_uuid' => $this->sessionCompany(),
                ],
                [
                    'with_table' => true,
                ]
            );

            if (is_array($facilitator)) {
                $input['facilitator_uuid'] = Utils::get($facilitator, 'uuid');
                $input['facilitator_type'] = $this->getModelClassName(Utils::get($facilitator, 'table'));
            }
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = $this->getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => $this->sessionCompany(),
                ],
                [
                    'with_table' => true,
                ]
            );

            if (is_array($customer)) {
                $input['customer_uuid'] = Utils::get($customer, 'uuid');
                $input['customer_type'] = $this->getModelClassName(Utils::get($customer, 'table'));
            }
        }

        // If adding a service quote for a purchase
        $serviceQuote = $this->resolveServiceQuote($request);
        if ($serviceQuote) {
            $order->purchaseServiceQuote($serviceQuote);
        }

        // dispatch if flagged true
        if ($request->boolean('dispatch')) {
            $order->dispatch();
        }

        // Ensure orchestrator_priority is never null on update either —
        // only apply the default when the key was explicitly sent as null/empty.
        if (array_key_exists('orchestrator_priority', $input) && !is_numeric($input['orchestrator_priority'])) {
            $input['orchestrator_priority'] = 50;
        }

        // update the order
        $order->update($input);
        $order->flushAttributesCache();

        // reroute, recompute distance and time, republish tracking and announce
        // `order.changed` for whatever this update changed
        $this->commitOrderEdit($edit);

        // load required relations
        $order->load(['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);

        // response the order resource
        return $this->orderResource($order);
    }

    /**
     * The cash to collect sent with an order update, as `payload.cod_amount` or a
     * top-level `cod_amount`. Zero is a valid amount; anything not numeric is ignored.
     */
    protected function codAmountFromRequest(Request $request): int|float|string|null
    {
        $value = null;
        if ($request->isArray('payload') && Arr::has((array) $request->input('payload'), 'cod_amount')) {
            $value = data_get($request->input('payload'), 'cod_amount');
        } elseif ($request->has('cod_amount')) {
            $value = $request->input('cod_amount');
        }

        return is_numeric($value) ? $value : null;
    }

    /**
     * The parts of an order update the edit policy looks at.
     */
    protected function orderEditInputFromRequest(Request $request, mixed $codAmount = null): array
    {
        $routeKeys = ['pickup', 'dropoff', 'return', 'waypoints', 'entities'];

        if ($request->isArray('payload')) {
            $edit = Arr::only((array) $request->input('payload'), $routeKeys);
        } elseif ($request->has('payload')) {
            // switching to another payload replaces the whole route
            $edit = ['pickup' => true, 'dropoff' => true, 'waypoints' => true];
        } else {
            $edit = $request->only($routeKeys);
        }

        foreach (['scheduled_at', 'notes', 'customer'] as $key) {
            if ($request->has($key)) {
                $edit[$key] = $request->input($key);
            }
        }

        if ($codAmount !== null) {
            $edit['cod_amount'] = $codAmount;
        }

        return $edit;
    }

    protected function applyPayloadEntities(Payload $payload, mixed $entities, string $mode): void
    {
        if ($mode === OrderEdit::ENTITIES_REPLACE && is_array($entities)) {
            $payload->syncEntities($entities);

            return;
        }

        if ($entities) {
            $payload->setEntities($entities);
        }
    }

    protected function beginOrderEdit(Order $order): OrderEdit
    {
        return OrderEdit::begin($order);
    }

    protected function commitOrderEdit(OrderEdit $edit): array
    {
        return $edit->commit();
    }

    protected function lockedEditResponse(OrderEditLockedException $exception)
    {
        return response()->apiError($exception->getMessage(), 422, $exception->toResponseData());
    }

    protected function orderCreateInputFromRequest(Request $request): array
    {
        return $request->only([
            'internal_id', 'payload', 'service_quote', 'purchase_rate',
            'adhoc', 'adhoc_distance', 'pod_method', 'pod_required',
            'scheduled_at', 'status', 'meta', 'notes',
            // Orchestrator constraints
            'time_window_start', 'time_window_end',
            'required_skills', 'orchestrator_priority',
        ]);
    }

    protected function orderUpdateInputFromRequest(Request $request): array
    {
        return $request->only([
            'internal_id', 'payload', 'adhoc', 'adhoc_distance',
            'pod_method', 'pod_required', 'scheduled_at', 'meta', 'type', 'status', 'notes',
            // Orchestrator constraints
            'time_window_start', 'time_window_end',
            'required_skills', 'orchestrator_priority',
        ]);
    }

    protected function payloadShapeFromRequest(Request $request): array
    {
        return $this->payloadShapeFromArray($request->only(['pickup', 'dropoff', 'return', 'waypoints', 'entities']));
    }

    protected function payloadShapeFromArray(array $payloadInput): array
    {
        $hasPickupField    = array_key_exists('pickup', $payloadInput);
        $hasDropoffField   = array_key_exists('dropoff', $payloadInput);
        $hasReturnField    = array_key_exists('return', $payloadInput);
        $hasWaypointsField = array_key_exists('waypoints', $payloadInput);

        return [
            'entities'                  => data_get($payloadInput, 'entities', []),
            'waypoints'                 => data_get($payloadInput, 'waypoints', []),
            'pickup'                    => data_get($payloadInput, 'pickup'),
            'dropoff'                   => data_get($payloadInput, 'dropoff'),
            'return'                    => data_get($payloadInput, 'return'),
            'has_pickup_field'          => $hasPickupField,
            'has_dropoff_field'         => $hasDropoffField,
            'has_return_field'          => $hasReturnField,
            'has_waypoints_field'       => $hasWaypointsField,
            'has_route_endpoint_fields' => $hasPickupField || $hasDropoffField || $hasReturnField,
        ];
    }

    /**
     * Query for Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function query(Request $request)
    {
        set_time_limit(180);

        $results = Order::queryWithRequest($request, function (&$query, $request) {
            $query->with(['trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'customer', 'facilitator']);
            $query->where('company_uuid', session('company'));
            $query->whereNotNull('payload_uuid');

            if ($request->has('payload')) {
                $query->whereHas('payload', function ($q) use ($request) {
                    $q->where('public_id', $request->input('payload'));
                });
            }

            if ($request->has('pickup')) {
                $query->whereHas('payload.pickup', function ($q) use ($request) {
                    $q->where('public_id', $request->input('pickup'));
                });
            }

            if ($request->has('dropoff')) {
                $query->whereHas('payload.dropoff', function ($q) use ($request) {
                    $q->where('public_id', $request->input('dropoff'));
                });
            }

            if ($request->has('return')) {
                $query->whereHas('payload.return', function ($q) use ($request) {
                    $q->where('public_id', $request->input('return'));
                });
            }

            if ($request->has('entity')) {
                $query->whereHas('payload.entities', function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('public_id', $request->input('entity'));
                        $q->orWhere('internal_id', $request->input('entity'));
                    });
                });
            }

            if ($request->has('entity_status')) {
                $query->whereHas('payload.entities.trackingNumber.status', function ($q) use ($request) {
                    if ($request->isArray('entity_status')) {
                        $q->whereIn('code', $request->input('entity_status'));
                    } else {
                        $q->where('code', $request->input('entity_status'));
                    }
                });
            }

            if ($request->filled('on')) {
                $on = Carbon::parse($request->input('on'));

                $query->where(function ($q) use ($on) {
                    $q->whereDate('created_at', $on);
                    $q->orWhereDate('scheduled_at', $on);
                });
            }

            if ($request->boolean('pod_required')) {
                $query->where('pod_required', 1);
            }

            if ($request->boolean('dispatched')) {
                $query->where('dispatched', 1);
            }

            if ($request->has('nearby')) {
                $nearby           = $request->input('nearby');
                $distance         = 6000; // default in meters
                $company          = Company::currentSession();
                $addedNearbyQuery = false;

                if ($company) {
                    $distance = $company->getOption('fleetops.adhoc_distance', 6000);
                }

                // if wants to find nearby place or coordinates
                if (Utils::isCoordinates($nearby)) {
                    $location = Utils::getPointFromMixed($nearby);

                    $query->whereHas('payload', function ($q) use ($location, $distance) {
                        $q->whereHas('pickup', function ($q) use ($location, $distance) {
                            $q->whereNotNull('location')->whereRaw('
                                ST_Y(location) BETWEEN -90 AND 90
                                AND ST_X(location) BETWEEN -180 AND 180
                                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
                            ');
                            $q->distanceSphere('location', $location, $distance);
                            $q->distanceSphereValue('location', $location);
                        })->orWhereHas('waypoints', function ($q) use ($location, $distance) {
                            $q->whereNotNull('location')->whereRaw('
                                ST_Y(location) BETWEEN -90 AND 90
                                AND ST_X(location) BETWEEN -180 AND 180
                                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
                            ');
                            $q->distanceSphere('location', $location, $distance);
                            $q->distanceSphereValue('location', $location);
                        });
                    });

                    // Update so additional nearby queries are not added
                    $addedNearbyQuery = true;
                }

                // request wants to find orders nearby a driver ?
                //
                // Unreachable in full: `Utils::isCoordinates()` resolves a
                // `driver_*` public id to that driver's location, so the
                // coordinates branch above consumes every driver that has one.
                // What reaches here is a driver with no stored location, and the
                // distance query below then raises on the null point rather than
                // completing — see the "without a stored location" case in
                // OrderControllerNearbyFiltersTest.
                // @codeCoverageIgnoreStart
                if ($addedNearbyQuery === false && is_string($nearby) && Str::startsWith($nearby, 'driver_')) {
                    $driver = Driver::where('public_id', $nearby)->first();

                    if ($driver) {
                        $query->whereHas('payload', function ($q) use ($driver, $distance) {
                            $q->whereHas('pickup', function ($q) use ($driver, $distance) {
                                $q->whereNotNull('location')->whereRaw('
                                    ST_Y(location) BETWEEN -90 AND 90
                                    AND ST_X(location) BETWEEN -180 AND 180
                                    AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
                                ');
                                $q->distanceSphere('location', $driver->location, $distance);
                                $q->distanceSphereValue('location', $driver->location);
                            })->orWhereHas('waypoints', function ($q) use ($driver, $distance) {
                                $q->whereNotNull('location')->whereRaw('
                                    ST_Y(location) BETWEEN -90 AND 90
                                    AND ST_X(location) BETWEEN -180 AND 180
                                    AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
                                ');
                                $q->distanceSphere('location', $driver->location, $distance);
                                $q->distanceSphereValue('location', $driver->location);
                            });
                        });

                        // Update so additional nearby queries are not added
                        $addedNearbyQuery = true;
                    }
                }
                // @codeCoverageIgnoreEnd

                // if is a string like address string
                if ($addedNearbyQuery === false && is_string($nearby)) {
                    $nearby = Place::createFromMixed($nearby, [], false);

                    if ($nearby instanceof Place) {
                        $query->whereHas('payload', function ($q) use ($nearby, $distance) {
                            $q->whereHas('pickup', function ($q) use ($nearby, $distance) {
                                $q->whereNotNull('location')->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
                                $q->distanceSphere('location', $nearby->location, $distance);
                                $q->distanceSphereValue('location', $nearby->location);
                            })->orWhereHas('waypoints', function ($q) use ($nearby, $distance) {
                                $q->whereNotNull('location')->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
                                $q->distanceSphere('location', $nearby->location, $distance);
                                $q->distanceSphereValue('location', $nearby->location);
                            });
                        });

                        // Update so additional nearby queries are not added
                        $addedNearbyQuery = true;
                    }
                }
            }
        });

        return OrderResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function find($id, Request $request)
    {
        // find for the order
        try {
            $order = $this->findOrder($id, ['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // response the order resource
        return $this->orderResource($order);
    }

    /**
     * Deletes a Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // delete the order
        $order->delete();

        // response the order resource
        return $this->deletedOrderResource($order);
    }

    /**
     * Returns current distance and time matrix for an order.
     *
     * @return \Illuminate\Http\Response $response
     */
    public function getDistanceMatrix(string $id)
    {
        // find the order
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $order->load(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);

        $origin      = $order->payload->pickup ?? $order->payload->waypoints->first();
        $destination = $order->payload->dropoff ?? $order->payload->waypoints->firstWhere('current_waypoint_uuid', $order->current_waypoint_uuid);

        $matrix = $this->drivingDistanceAndTime($origin, $destination);

        $order->update(['distance' => $matrix->distance, 'time' => $matrix->time]);

        // response distance and time matrix
        return $this->jsonResponse($matrix);
    }

    /**
     * Dispatches an order.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function dispatchOrder(string $id)
    {
        try {
            $order = $this->findOrder($id, ['trackingNumber', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'purchaseRate.serviceQuote.items', 'customer', 'facilitator']);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if (!$order->hasDriverAssigned && !$order->adhoc) {
            return $this->apiError('No driver assigned to dispatch!');
        }

        if ($order->dispatched) {
            return $this->apiError('Order has already been dispatched!');
        }

        $order->dispatch();
        $order->insertDispatchActivity();

        return $this->orderResource($order);
    }

    /**
     * Schedules an order using date and time.
     *
     * @param ScheduleOrderRequest
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function scheduleOrder(string $id, ScheduleOrderRequest $request)
    {
        $dateInput = $request->input('date');
        $timeInput = $request->input('time');

        // get the default tz
        $defaultTz     = $this->defaultCompanyTimezone();
        $timezoneInput = $request->input('timezone', $defaultTz);

        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // Parse date and time
        $date = Carbon::parse($dateInput);
        if ($timeInput) {
            $time = Carbon::parse($timeInput);
            // Combine date and time
            $date->setTime($time->hour, $time->minute, $time->second);
        }

        // Set the timezone
        $date->shiftTimezone($timezoneInput);

        // Update order with new date and time
        $order->scheduled_at = $date;
        $order->save();

        return $this->orderResource($order);
    }

    /**
     * Request to start order, this assumes order is dispatched.
     * Unless there is a param to skip dispatch throw a order not dispatched error.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function startOrder(string $id, Request $request)
    {
        $skipDispatch      = $request->or(['skip_dispatch', 'skipDispatch'], false);
        $assignAdhocDriver = $request->input('assign');

        try {
            $order = $this->findOrder($id, ['payload.waypoints', 'driverAssigned']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if ($order->started) {
            return response()->apiError('Order has already started.');
        }

        // if the order is adhoc and the parameter of `assign` is set with a valid driver id, assign the driver and continue
        if ($order->adhoc && $assignAdhocDriver && Str::startsWith($assignAdhocDriver, 'driver_')) {
            $order->assignDriver($assignAdhocDriver, true);
        }

        /** @var \Fleetbase\Models\Driver */
        $driver = $this->findDriverByUuid($order->driver_assigned_uuid);

        /** @var \Fleetbase\Models\Payload */
        $payload = $this->findPayloadByUuid($order->payload_uuid);

        if ($order->adhoc && !$driver) {
            return response()->apiError('You must send driver to accept adhoc order.');
        }

        if (!$driver) {
            return response()->apiError('No driver assigned to order.');
        }

        // Get the order config
        $orderConfig = $order->config();

        // Get the order started activity
        $activity = $orderConfig->getStartedActivity();

        // Order is not dispatched if next activity code is dispatch or order is not flagged as dispatched
        $isNotDispatched = $order->isNotDispatched;

        // If order is not dispatched yet $activity->is('dispatched') || $order->dispatched === true
        // and not skipping throw order not dispatched error
        if ($isNotDispatched && !$skipDispatch) {
            return response()->apiError('Order has not been dispatched yet and cannot be started.');
        }

        // set order to started
        $order->started    = true;
        $order->started_at = now();
        $order->save();

        // trigger start event
        try {
            event(new OrderStarted($order));
        } catch (\Exception $e) {
            Log::debug('Unable to complete order started event', ['error' => $e->getMessage(), 'order' => $order]);
        }

        // set order as drivers current order
        $driver->current_job_uuid = $order->uuid;
        $driver->save();

        // set first service stop destination for multi-stop routes
        if ($this->payloadUsesServiceStopActivity($payload)) {
            $this->ensurePayloadCurrentServiceStop($payload);
        }
        $order->setRelation('payload', $payload);

        // update order activity
        $updateActivityRequest = new Request(['activity' => $activity->serialize()]);

        // update activity
        return $this->updateActivity($order, $updateActivityRequest);
    }

    /**
     * Update an order activity.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function updateActivity($id, Request $request)
    {
        $skipDispatch = $request->or(['skip_dispatch', 'skipDispatch'], false);
        $proof        = $request->input('proof', null);
        $order        = null;

        // if instance of order is passed directly to this method
        if ($id instanceof Order) {
            /** @var Order $order */
            $order = $id;
        }

        // if string $id
        if (!$order) {
            try {
                $order = $this->findOrder($id, [
                    'driverAssigned',
                    'payload.entities',
                    'payload.pickup',
                    'payload.dropoff',
                    'payload.return',
                    'payload.currentWaypoint',
                    'payload.waypoints',
                    'payload.waypointMarkers.place',
                    'payload.waypointMarkers.trackingNumber.status',
                ]);
            } catch (ModelNotFoundException $exception) {
                return response()->apiError('Order resource not found.', 404);
            }
        }

        // if no order found
        //
        // Unreachable: findOrder() above is typed `: Order`, so it either returns
        // an order or throws — it never yields null. This is independent of the
        // upstream findByIdOrFail defect and stays unreachable after that is
        // fixed; the catch above is what becomes live then, not this guard.
        // @codeCoverageIgnoreStart
        if (!$order) {
            return response()->apiError('Order resource not found.', 404);
        }
        // @codeCoverageIgnoreEnd

        // if order is still status of `created` trigger started flag
        if ($order->status === 'created') {
            $order->started    = true;
            $order->started_at = now();
        }

        // if order is already completed
        if ($order->status === 'completed') {
            return response()->apiError('Order is already completed.');
        }

        // Get the order config
        $orderConfig = $order->config();
        $activity    = $request->array('activity');
        if (!Utils::isActivity($activity)) {
            $activity = new Activity($activity, $order->getConfigFlow());
        }

        // if we're going to skip the dispatch get the next activity status and flow and continue
        if (Utils::isActivity($activity) && $activity->is('dispatched') && $skipDispatch) {
            $activity = $orderConfig->getStartedActivity();
        }

        // handle pickup/dropoff order activity update as normal
        if (Utils::isActivity($activity) && $activity->is('dispatched')) {
            // make sure driver is assigned if not trigger failed dispatch
            if (!$order->hasDriverAssigned && !$order->adhoc) {
                event(new OrderDispatchFailed($order, 'No driver assigned for order to dispatch to.'));

                return response()->apiError('No driver assigned for order to dispatch to.');
            }

            $order->dispatch();

            return $this->orderResource($order);
        }

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point */
        $location = $order->getLastLocation();

        $usesServiceStopActivity = $this->payloadUsesServiceStopActivity($order->payload);
        $isLifecycleActivity     = Utils::isActivity($activity) && in_array($activity->code, ['created', 'dispatched', 'started'], true);

        // Classic pickup/dropoff orders and static lifecycle statuses update the order.
        if (!$usesServiceStopActivity || $isLifecycleActivity) {
            // Update order activity
            $order->updateActivity($activity, $proof);

            if (!$usesServiceStopActivity) {
                foreach ($order->payload->entities as $entity) {
                    $entity->insertActivity($activity, $location, $proof);
                }
            }

            if ($usesServiceStopActivity && $activity->is('started')) {
                $this->ensurePayloadCurrentServiceStop($order->payload);
            }

            // Handle order completion
            if (Utils::isActivity($activity) && $activity->completesOrder()) {
                $order->driverAssigned?->unassignCurrentJob();
                $order->complete($this->resolveProof($proof));
            }

            return $this->orderResource($order->refresh());
        }

        if (!$order->started && in_array($order->status, ['created', 'dispatched'], true)) {
            return response()->apiError('Order must be started before waypoint activity can be updated.', 422);
        }

        $currentStop               = $this->ensurePayloadCurrentServiceStop($order->payload);
        $isCompletingCurrentStop   = Utils::isActivity($activity) && $activity->complete();

        $this->updateCurrentServiceStopActivity($order, $activity, $location, $proof);

        if (Utils::isActivity($activity) && $activity->is('canceled')) {
            $order->driverAssigned?->unassignCurrentJob();
            $order->cancel();

            return $this->orderResource($order->refresh());
        }

        if (Utils::isActivity($activity) && $activity->completesOrder()) {
            $nextStop = $isCompletingCurrentStop ? $this->advanceCurrentServiceStopDestination($order, $order->payload) : null;

            if (!$nextStop || !$currentStop) {
                $order->driverAssigned?->unassignCurrentJob();
                $order->complete($this->resolveProof($proof));
            }
        }

        return $this->orderResource($order->refresh());
    }

    /**
     * Retrieve the next activity for the order flow.
     *
     * @return \Illuminate\Http\Response
     */
    public function getNextActivity(string $id, Request $request)
    {
        $waypointId = $request->input('waypoint');

        try {
            $order = $this->findOrder($id, [
                'payload.pickup',
                'payload.dropoff',
                'payload.return',
                'payload.waypoints',
                'payload.waypointMarkers.place',
                'payload.waypointMarkers.trackingNumber.status',
            ]);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $canUpdateServiceStopActivity = $this->payloadUsesServiceStopActivity($order->payload)
            && ($order->started || $order->started_at || !in_array($order->status, ['created', 'dispatched'], true));
        $stop = null;
        if ($canUpdateServiceStopActivity) {
            $stop = $waypointId
                ? $this->resolveServiceStopFromKey($order->payload, $waypointId)
                : $this->payloadCurrentServiceStop($order->payload);
        }

        $orderConfig = $order->ensureOrderConfig();
        if (!$orderConfig) {
            return response()->apiError('No order config found for order.');
        }
        $activities = $stop ? $this->nextActivitiesForServiceStop($order, $order->payload, $stop) : $orderConfig->nextActivity();

        // If activity is to complete order add proof of delivery properties if required
        // This is a temporary fix until activity is updated to handle POD on it's own
        $activities = $activities->map(function ($activity) use ($order) {
            if ($activity->completesOrder() && $order->pod_required) {
                $activity->set('require_pod', true);
                $activity->set('pod_method', $order->pod_method);
            }

            // resolved status and details
            $activity->set('_resolved_status', TemplateString::resolve($activity->get('status', ''), $order));
            $activity->set('_resolved_details', TemplateString::resolve($activity->get('details', ''), $order));

            return $activity;
        });

        return response()->json($activities);
    }

    /**
     * Confirms and completes an order.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function completeOrder(string $id)
    {
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // confirm every waypoint is completed
        $isCompleted = $order->payload->waypointMarkers->every(function ($waypoint) {
            return $waypoint->status_code === 'COMPLETED';
        });

        // if not completed respond with error
        if (!$isCompleted) {
            return response()->apiError('Not all waypoints completed for order.');
        }

        $activity = $order->config()->getCompletedActivity();
        if ($order->driverAssigned) {
            // unset from driver current job
            $order->driverAssigned->unassignCurrentOrder();
        }

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point */
        $location = $order->getLastLocation();
        $order->setStatus($activity->code);
        $order->insertActivity($activity, $location);
        $order->notifyCompleted();

        return $this->orderResource($order);
    }

    /**
     * Updates a order to canceled and updates order activity.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function cancelOrder(string $id, ?Request $request = null)
    {
        // Laravel does not inject a class-typed parameter that has a default, so the
        // current request is read here; the default keeps cancelOrder($id) callable.
        $request = $request ?? request();

        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // the reason becomes the canceled status details and is sent with the canceled notifications
        $reason = $this->reasonFromRequest($request);
        if ($reason !== null) {
            $order->cancelWithReason($reason);
        } else {
            $order->cancel();
        }

        return $this->orderResource($order);
    }

    /**
     * The free-text `reason` sent with a cancel, trimmed to 1000 characters.
     */
    protected function reasonFromRequest(Request $request): ?string
    {
        $reason = $request->input('reason');
        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }

        return Str::limit(trim($reason), 1000, '');
    }

    /**
     * Mark a specific place (waypoint, pickup, or drop-off) as the current
     * destination for an order payload.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function setDestination(string $id, string $placeId)
    {
        try {
            // Serialize stop transitions with photo, signature and QR captures,
            // which lock the same parent Order row before rechecking the stop.
            $result = DB::transaction(function () use ($id, $placeId) {
                $order = $this->findOrder($id, [
                    'payload.pickup',
                    'payload.dropoff',
                    'payload.return',
                    'payload.waypoints',
                    'payload.waypointMarkers.place',
                    'payload.waypointMarkers.trackingNumber.status',
                    'driverAssigned',
                    'vehicleAssigned',
                    'customer',
                    'facilitator',
                ], [], true);

                $payload = $order->payload;
                if (!$payload instanceof Payload || !$this->payloadUsesServiceStopActivity($payload)) {
                    return response()->apiError('Destination can only be changed for multi-waypoint orders.', 422);
                }
                $stop = $this->resolveServiceStopFromKey($payload, $placeId);
                if (!$stop) {
                    return response()->apiError('Place resource is not a valid destination.', 422);
                }
                $this->setPayloadCurrentServiceStop($payload, $stop);
                return $order;
            });
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Order resource not found.', 404);
        }

        return $result instanceof Order ? $this->orderResource($result->refresh()) : $result;
    }

    /**
     * Sends request for route optimization and re-sorts waypoints.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function optimize(string $id)
    {
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        }

        // do this code

        return $this->orderResource($order);
    }

    /**
     * Get important tracking data about the order.
     *
     * @return \Illuminate\Http\Response
     */
    public function trackerData(Request $request, string $id)
    {
        set_time_limit(280);

        try {
            $order = $this->findOrder($id);
            $data  = $order->tracker()->toArray($request->only(['provider', 'fallbacks', 'traffic_enabled']));

            return $this->jsonResponse($data);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        } catch (\Throwable $e) {
            return $this->apiError('An error occured trying to track order.', 404);
        }

        return $this->apiError('An error occured trying to track order.', 404);
    }

    /**
     * Get important ETA data about the order.
     *
     * @return \Illuminate\Http\Response
     */
    public function etaData(Request $request, string $id)
    {
        try {
            $order = $this->findOrder($id);
            $data  = $order->tracker()->eta($request->only(['provider', 'fallbacks', 'traffic_enabled']));

            return $this->jsonResponse($data);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        } catch (\Throwable $e) {
            return $this->apiError('An error occured trying to track order.', 404);
        }

        return $this->apiError('An error occured trying to track order.', 404);
    }

    /**
     * Verify & Capture QR Code Scan.
     *
     * @return void
     */
    public function captureQrScan(Request $request, string $id, ?string $subjectId = null)
    {
        $code = $request->input('code');
        if (!is_string($code) || $code === '') {
            return $this->apiError('No QR code data to capture.');
        }
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $exception) {
            return $this->apiError('Order resource not found.', 404);
        }
        $type = $subjectId ? strtok($subjectId, '_') : null;
        try {
            $proof = DB::transaction(function () use ($order, $request, $type, $subjectId, $code) {
                [$lockedOrder, $subject] = $this->lockProofCaptureSubject($order, $type, $subjectId);
                if (!TrackingCode::matches($code, $subject)) {
                    throw new HttpResponseException(response()->json(['error' => 'Unable to validate QR code data.'], 400));
                }
                // Current locking read also sees a competing transaction's QR
                // proof after waiting for the parent order, under MySQL RR.
                $existing = Proof::where('company_uuid', $lockedOrder->company_uuid)
                    ->where('order_uuid', $lockedOrder->uuid)->where('subject_uuid', $subject->uuid)
                    ->where('subject_type', Utils::getModelClassName($subject))
                    ->where('remarks', 'Verified by QR Code Scan')->lockForUpdate()->first();
                return $existing ?? $this->createProof([
                    'company_uuid' => $lockedOrder->company_uuid,
                    'order_uuid' => $lockedOrder->uuid,
                    'subject_uuid' => $subject->uuid,
                    'subject_type' => Utils::getModelClassName($subject),
                    'remarks' => 'Verified by QR Code Scan',
                    'raw_data' => $request->input('raw_data'),
                    'data' => $request->input('data', []),
                ]);
            });
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        } catch (\Throwable $exception) {
            Log::warning('Proof QR persistence failed', ['exception_class' => get_class($exception)]);
            return $this->apiError('Unable to store proof of delivery.', 503);
        }
        return $this->proofResource($proof);
    }

    /**
     * Validate a QR code.
     *
     * @return void
     */
    public function captureSignature(Request $request, string $id, ?string $subjectId = null)
    {
        $disk         = $request->input('disk', config('filesystems.default'));
        $bucket       = $request->input('bucket', config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket')));
        $signature    = $request->input('signature');
        $data         = $request->input('data', []);
        $remarks      = $request->input('remarks', 'Verified by Signature');
        $type         = $subjectId ? strtok($subjectId, '_') : null;

        if (!$signature) {
            return $this->apiError('No signature data to capture.');
        }

        // Validate actual PNG bytes before any database or storage mutation.
        if (!is_string($signature)) {
            return $this->apiError('Invalid signature data.', 422);
        }
        $base64Payload = str_starts_with($signature, 'data:image/png;base64,')
            ? substr($signature, strlen('data:image/png;base64,'))
            : $signature;
        if ($base64Payload === '' || strlen($base64Payload) > 4 * (int) ceil((10 * 1024 * 1024) / 3)) {
            return $this->apiError('Invalid signature data.', 422);
        }
        $signatureContents = base64_decode($base64Payload, true);
        if (!is_string($signatureContents) || strlen($signatureContents) > 10 * 1024 * 1024) {
            return $this->apiError('Invalid signature data.', 422);
        }
        $imageInfo = @getimagesizefromstring($signatureContents);
        if (!is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== 'image/png'
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($signatureContents) !== 'image/png'
            || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1
            || $imageInfo[0] > 10000 || $imageInfo[1] > 10000
            || $imageInfo[0] > intdiv(25000000, $imageInfo[1])
            || !function_exists('imagecreatefromstring')
            || @imagecreatefromstring($signatureContents) === false) {
            return $this->apiError('Invalid signature data.', 422);
        }

        // Load Order
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        }

        // Resolve subject
        $subject = $this->resolveSubject($order, $type, $subjectId);
        if (!$subject) {
            return $this->apiError('Unable to capture signature data.');
        }

        // Reject cross-tenant or terminal-order writes before any side effects.
        $companyUuid = session('company');
        if (!is_string($companyUuid) || $companyUuid === ''
            || $order->company_uuid !== $companyUuid) {
            return $this->apiError('Proof is not authorized for this company.', 403);
        }
        if (in_array($order->status, ['completed', 'canceled'], true)) {
            return $this->apiError('Proof capture is closed for this order.', 409);
        }

        try {
            $this->assertProofCaptureAllowed($order, $subject);
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }

        // Keep proof + file rows atomic. External storage needs compensating
        // cleanup, as it cannot participate in the database transaction.
        $storedPaths = [];
        try {
            $proof = DB::transaction(function () use (
                $order, $subject, $remarks, $signature, $signatureContents,
                $data, $disk, $bucket, $companyUuid, $type, $subjectId, &$storedPaths
            ) {
                [$lockedOrder, $subject] = $this->lockProofCaptureSubject($order, $type, $subjectId);
                $proof = $this->createProof([
                    'company_uuid' => $companyUuid,
                    'order_uuid'   => $lockedOrder->uuid,
                    'subject_uuid' => $subject->uuid,
                    'subject_type' => Utils::getModelClassName($subject),
                    'remarks'      => $remarks,
                    'raw_data'     => $signature,
                    'data'         => $data,
                ]);
                $path = 'uploads/' . $companyUuid . '/signatures/' . $proof->public_id . '.png';
                $storedPaths[] = $path;
                $this->putStorage($disk, $path, $signatureContents);

                $file = $this->createFile([
                    'company_uuid'      => $companyUuid,
                    'uploader_uuid'     => session('user'),
                    'name'              => basename($path),
                    'original_filename' => basename($path),
                    'extension'         => 'png',
                    'content_type'      => 'image/png',
                    'path'              => $path,
                    'bucket'            => $bucket,
                    'disk'              => $disk,
                    'type'              => 'signature',
                    'file_size'         => strlen($signatureContents),
                ])->setKey($proof);
                $proof->file_uuid = $file->uuid;
                $proof->save();

                return $proof;
            });
        } catch (HttpResponseException $exception) {
            $this->cleanupIncompleteProofFiles($disk, $storedPaths);
            return $exception->getResponse();
        } catch (\Throwable $exception) {
            $this->cleanupIncompleteProofFiles($disk, $storedPaths);
            Log::warning('Proof signature persistence failed', ['exception_class' => get_class($exception)]);
            return $this->apiError('Unable to store proof of delivery.', 503);
        }

        return $this->proofResource($proof);
    }

    /**
     * Capture one or more photos for an order (as proof) and persist them.
     *
     * This endpoint supports **both**:
     *  - `multipart/form-data` uploads (key: `photos[]`)
     *  - JSON payload with Base64-encoded images (key: `photos`: [string…])
     *
     * It will:
     *  1. Validate that `photos` is a non-empty array of files or strings.
     *  2. Resolve the target Order and optional subject (waypoint/place/entity).
     *  3. Loop through each upload or blob, create a Proof record, decode/store the image,
     *     then create a File record and link it to the Proof.
     *
     * @param string      $id        UUID or primary key of the Order
     * @param string|null $subjectId Optional “subject” identifier (e.g. waypoint_publicId)
     *
     * @return \Fleetbase\FleetOps\Http\Resources\ProofResource
     *
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function capturePhoto(Request $request, string $id, ?string $subjectId = null)
    {
        // Validate incoming payload
        try {
            $request->validate([
                'photos'   => 'required|array|min:1|max:10',
                'photos.*' => [
                    function ($attribute, $value, $fail) {
                        // 1) If it’s a file, ensure it’s an image ≤ 10 MB
                        if ($value instanceof UploadedFile) {
                            if ($this->inspectIncomingProofImage($value) === null) {
                                $fail("{$attribute} must contain a valid image of at most 10 MB.");
                            }
                            return;
                        }

                        // 2) Otherwise it must be a valid Base64 string
                        if (is_string($value)) {
                            if ($this->inspectIncomingProofImage($value) === null) {
                                $fail("{$attribute} must contain a valid image of at most 10 MB.");
                            }

                            return;
                        }

                        // 3) Anything else is invalid
                        $fail("{$attribute} must be an image file or a Base64 string.");
                    },
                ],
                'remarks'  => 'sometimes|string|max:255',
                'data'     => 'sometimes|array',
            ]);
        } catch (ValidationException $e) {
            $errorMessage = collect($e->errors())->flatten()->first();

            return $this->apiError($errorMessage, 422);
        }

        // Determine storage disk & bucket
        $disk        = $request->input('disk', config('filesystems.default'));
        $bucket      = $request->input(
            "filesystems.disks.{$disk}.bucket",
            config("filesystems.disks.{$disk}.bucket", config('filesystems.disks.s3.bucket'))
        );
        if (!is_string($bucket)) {
            $bucket = '';
        }

        // Collect uploads & Base64 strings
        /** @var UploadedFile[] $rawInputs */
        $rawInputs   = $request->file('photos', []);
        /** @var string[] $base64Inputs */
        $base64Inputs = array_filter(
            $request->input('photos', []),
            function ($value) {
                // must be a string AND strictly decodable as Base64
                // Keep malformed strings in the batch so preflight rejects
                // the entire request rather than silently dropping an item.
                return is_string($value);
            }
        );

        $remarks     = $request->input('remarks', 'Verified by Photo');
        $data        = $request->input('data', []);
        $type        = $subjectId ? strtok($subjectId, '_') : null;

        // Normalize into one array
        $incoming = array_merge($rawInputs, $base64Inputs);

        // Fail closed even when the request validator is bypassed in a test
        // harness: no input and a malformed batch are distinct cases.
        if (empty($incoming)) {
            return $this->apiError('No photo data to capture.');
        }
        // Multipart requests can expose an uploaded file in both the
        // validation input bag and the files bag. Count actual uploads once.
        // JSON-only input still rejects silently filtered non-string values.
        if (count($incoming) > 10 || (empty($rawInputs) &&
            count($incoming) !== count($request->input('photos', [])))) {
            return $this->apiError('Photo batch contains invalid inputs.', 422);
        }

        // Validate the complete batch before creating any database rows or files.
        $totalImageBytes = 0;
        foreach ($incoming as $item) {
            $image = $this->inspectIncomingProofImage($item);
            if ($image === null) {
                return $this->apiError('Photo batch contains an invalid image.', 422);
            }
            $totalImageBytes += $image['size'];
            if ($totalImageBytes > 20 * 1024 * 1024) {
                return $this->apiError('Photo batch must contain at most 20 MB of images.', 422);
            }
        }

        // Load Order
        try {
            $order = $this->findOrder($id, ['payload.pickup', 'payload.dropoff', 'payload.return', 'payload.waypoints', 'payload.waypointMarkers.place']);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        }

        // Resolve subject
        $subject = $this->resolveSubject($order, $type, $subjectId);
        if (!$subject) {
            return $this->apiError('Unable to capture photo as proof.');
        }

        // Prevent writes to another tenant or to a closed order.
        $companyUuid = session('company');
        if (!is_string($companyUuid) || $companyUuid === ''
            || $order->company_uuid !== $companyUuid) {
            return $this->apiError('Proof is not authorized for this company.', 403);
        }
        if (in_array($order->status, ['completed', 'canceled'], true)) {
            return $this->apiError('Proof capture is closed for this order.', 409);
        }

        try {
            $this->assertProofCaptureAllowed($order, $subject);
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }

        // Entire batch is a SQL unit of work. Compensate filesystem side
        // effects if any File/Proof insertion fails before commit.
        $storedPaths = [];
        try {
            $proof = DB::transaction(function () use (
                $order, $subject, $incoming, $remarks, $data, $disk,
                $bucket, $companyUuid, $type, $subjectId, &$storedPaths
            ) {
                [$lockedOrder, $subject] = $this->lockProofCaptureSubject($order, $type, $subjectId);
                foreach ($incoming as $item) {
                    $image = $this->inspectIncomingProofImage($item);
                    if ($image === null) {
                        throw new \InvalidArgumentException('Invalid image during persistence.');
                    }
                    $proof = $this->createProof([
                        'company_uuid' => $companyUuid,
                        'order_uuid'   => $lockedOrder->uuid,
                        'subject_uuid' => $subject->uuid,
                        'subject_type' => Utils::getModelClassName($subject),
                        'remarks'      => $remarks,
                        'raw_data'     => $item instanceof UploadedFile ? null : $item,
                        'data'         => $data,
                    ]);
                    $storedPaths[] = 'uploads/' . $companyUuid . '/photos/'
                        . $proof->public_id . '.' . $image['extension'];
                    $file = $this->storeProofPhoto(
                        proof: $proof,
                        photo: $item,
                        disk: $disk,
                        bucket: $bucket
                    );
                    $proof->update(['file_uuid' => $file->uuid]);
                }
                return $proof;
            });
        } catch (HttpResponseException $exception) {
            $this->cleanupIncompleteProofFiles($disk, $storedPaths);
            return $exception->getResponse();
        } catch (\Throwable $exception) {
            $this->cleanupIncompleteProofFiles($disk, $storedPaths);
            Log::warning('Proof photo persistence failed', ['exception_class' => get_class($exception)]);
            return $this->apiError('Unable to store proof of delivery.', 503);
        }

        return $this->proofResource($proof);
    }

    /**
     * Decode and store a single proof image, then create its File record.
     *
     * @param UploadedFile|string $photo  UploadedFile instance or Base64 string
     * @param string              $disk   Filesystem disk name
     * @param string              $bucket Storage bucket/key prefix
     *
     * @return \Feetbase\Models\File
     */
    protected function storeProofPhoto(Proof $proof, UploadedFile|string $photo, string $disk, string $bucket): File
    {
        $image = $this->inspectIncomingProofImage($photo);
        if ($image === null) {
            throw new \InvalidArgumentException('Invalid photo image payload.');
        }
        $contents    = $image['contents'];
        $extension   = $image['extension'];
        $contentType = $image['content_type'];

        $company = session('company');
        $path    = "uploads/{$company}/photos/{$proof->public_id}.{$extension}";

        $this->putStorage($disk, $path, $contents);

        return $this->createFile([
            'company_uuid'      => $company,
            'uploader_uuid'     => session('user'),
            'name'              => basename($path),
            'original_filename' => basename($path),
            'extension'         => $extension,
            'content_type'      => $contentType,
            'path'              => $path,
            'bucket'            => $bucket,
            'disk'              => $disk,
            'type'              => 'photo',
            'file_size'         => strlen($contents),
        ])->setKey($proof);
    }

    /**
     * Resolve the “subject” model based on type and public ID.
     *
     * Supported types:
     *  - null        → the Order itself
     *  - 'place', 'waypoint' → a Waypoint matching payload_uuid & public_id
     *  - 'entity'    → an Entity by public_id
     *  - 'order' or any other → the Order
     *
     * @param string|null $type      Type prefix extracted from subjectId
     * @param string|null $subjectId Full public_id of the subject
     *
     * @return Order|Waypoint|Entity|null
     */
    /**
     * Undo external storage side effects if the database transaction failed.
     * Only paths generated by the attempted operation are considered.
     */
    /** Re-check authorization under an order row lock before creating proofs. */
    protected function lockProofCaptureSubject(Order $order, ?string $type, ?string $subjectId): array
    {
        $locked = $this->findOrder($order->public_id, [
            'payload.pickup', 'payload.dropoff', 'payload.return',
            'payload.waypoints', 'payload.waypointMarkers.place',
            'payload.waypointMarkers.trackingNumber.status',
        ], [], true);
        $company = session('company');
        if (!is_string($company) || $company === ''
            || $locked->company_uuid !== $company || $locked->uuid !== $order->uuid) {
            throw new HttpResponseException(new \Illuminate\Http\JsonResponse(['error' => 'Proof is not authorized for this company.'], 403));
        }
        $subject = $this->resolveSubject($locked, $type, $subjectId);
        if (!$subject) {
            throw new HttpResponseException(new \Illuminate\Http\JsonResponse(['error' => 'Proof subject is unavailable.'], 409));
        }
        $this->assertProofCaptureAllowed($locked, $subject);
        return [$locked, $subject];
    }

    protected function assertProofCaptureAllowed(Order $order, mixed $subject): void
    {
        if (in_array($order->status, ['completed', 'canceled'], true)) {
            throw new HttpResponseException(new \Illuminate\Http\JsonResponse(['error' => 'Proof capture is closed for this order.'], 409));
        }
        $payload = $order->payload;
        if (!$payload instanceof Payload) {
            if ($subject instanceof Waypoint || $subject instanceof Place || $subject instanceof Entity) {
                throw new HttpResponseException(new \Illuminate\Http\JsonResponse(['error' => 'Proof requires an active order payload.'], 409));
            }
            return;
        }
        if (!$this->payloadUsesServiceStopActivity($payload)) {
            return;
        }
        $current = $this->payloadCurrentServiceStop($payload);
        $requested = $subject instanceof Waypoint || $subject instanceof Place
            ? $this->resolveServiceStopFromKey($payload, $subject->uuid) : null;
        $entityAtCurrentStop = $subject instanceof Entity && $current
            && $subject->destination_uuid === ($current['place']->uuid ?? null);
        if (!$current || (!$entityAtCurrentStop &&
            (!$requested || (int) $requested['sequence'] !== (int) $current['sequence']))
            || $this->serviceStopIsComplete($order, $payload, $current)) {
            throw new HttpResponseException(new \Illuminate\Http\JsonResponse(['error' => 'Proof must target the active unfinished stop.'], 409));
        }
    }

    protected function inspectProofImage(string $contents): ?array
    {
        $size = strlen($contents);
        if ($size === 0 || $size > 10 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($contents);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        if (!is_array($info) || ($info['mime'] ?? null) !== $mime
            || !isset($extensions[$mime])
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] > 10000 || $info[1] > 10000
            || $info[0] > intdiv(25000000, $info[1])
            || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $bitmap = @imagecreatefromstring($contents);
        if ($bitmap === false) {
            return null;
        }
        unset($bitmap);
        return [
            'contents' => $contents, 'size' => $size,
            'content_type' => $mime, 'extension' => $extensions[$mime],
        ];
    }

    protected function inspectIncomingProofImage(UploadedFile|string $photo): ?array
    {
        if ($photo instanceof UploadedFile) {
            $path = $photo->getRealPath();
            if (!$photo->isValid() || $photo->getSize() > 10 * 1024 * 1024
                || !is_string($path) || !is_file($path)) {
                return null;
            }
            $contents = file_get_contents($path);
        } else {
            if ($photo === '' || strlen($photo) > 4 * (int) ceil((10 * 1024 * 1024) / 3)) {
                return null;
            }
            $contents = base64_decode($photo, true);
        }
        return is_string($contents) ? $this->inspectProofImage($contents) : null;
    }

    protected function cleanupIncompleteProofFiles(string $disk, array $storedPaths): void
    {
        foreach (array_reverse($storedPaths) as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $exception) {
                Log::error('Proof file cleanup failed', [
                    'exception_class' => get_class($exception),
                    'storage_disk' => $disk,
                ]);
            }
        }
    }

    protected function resolveSubject(Order $order, ?string $type, ?string $subjectId)
    {
        if ($subjectId === null) {
            return $order;
        }

        // Never map an invalid explicit subject to the order itself.
        if ($subjectId === '' || !$type || !$order->company_uuid || !$order->payload_uuid) {
            return null;
        }

        if (in_array($type, ['place', 'waypoint'], true)) {
            $stop = $this->resolveServiceStopFromKey($order->payload, $subjectId);
            if ($stop) {
                return ($stop['waypoint'] ?? null) instanceof Waypoint
                    ? $stop['waypoint'] : ($stop['place'] ?? null);
            }
        }

        return match ($type) {
            'place', 'waypoint' => Waypoint::withoutGlobalScopes()
                ->where('payload_uuid', $order->payload_uuid)
                ->where(fn ($q) => $q
                    ->whereHas('place', fn ($q) => $q->where('public_id', $subjectId))
                    ->orWhere('public_id', $subjectId))
                ->first(),
            'entity' => Entity::withoutGlobalScopes()
                ->where('company_uuid', $order->company_uuid)
                ->where('payload_uuid', $order->payload_uuid)
                ->where('public_id', $subjectId)
                ->first(),
            'order' => in_array($subjectId, [$order->public_id, $order->uuid], true) ? $order : null,
            default => null,
        };
    }

    /**
     * Retrieve proof of delivery resources associated with a given order and optional subject.
     *
     * This method supports retrieving proofs related to the order itself or a subject within the order,
     * such as a waypoint, place, or entity. If a subject ID is provided, it will determine the subject type
     * based on its prefix and resolve the appropriate model. If no subject ID is provided, the order itself is used
     * as the subject.
     *
     * @param Request     $request   the incoming HTTP request instance
     * @param string      $id        the public ID of the order
     * @param string|null $subjectId Optional subject ID (e.g., waypoint, place, or entity).
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function proofs(Request $request, string $id, ?string $subjectId = null)
    {
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        }

        $subject = $order;
        if ($subjectId) {
            $type    = strtok($subjectId, '_');
            $subject = $this->resolveSubject($order, $type, $subjectId);
        }

        if (!$subject) {
            return $this->apiError('Unable to retrieve proof of delivery for subject.');
        }

        $proofs = $this->proofsForSubject($order, $subject);

        return $this->proofResourceCollection($proofs);
    }

    /**
     * Retrieves editable fields for a specific order entity based on its configuration.
     *
     * This function looks up an order by its ID and retrieves configurable editable fields
     * associated with it, as defined in the settings. If the order is not found, it returns
     * a 404 response with an error message. Otherwise, it returns the editable fields for
     * the order entity.
     *
     * @param string  $id      the unique identifier of the order
     * @param Request $request the incoming request instance
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response containing either an error message
     *                                       or the editable fields for the order entity
     *
     * @throws ModelNotFoundException thrown if the order with the given ID cannot be found
     */
    public function getEditableEntityFields(string $id, Request $request)
    {
        try {
            $order = $this->findOrder($id);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        }

        // Define settings as array
        $entityEditingSettings = [];

        // get the order config id
        $orderConfigId = data_get($order, 'order_config_uuid');

        // Get entity editing settings
        $savedEntityEditingSettings = $this->entityEditingSettings();
        if ($orderConfigId && $savedEntityEditingSettings) {
            $entityEditingSettings = data_get($savedEntityEditingSettings, $orderConfigId, []);
        }

        return $this->jsonResponse($entityEditingSettings);
    }

    /**
     * Get all order comments.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderComments(string $id)
    {
        try {
            $order = $this->findOrder($id);
            $order->loadMissing('comments');

            return $this->commentResourceCollection($order->comments);
        } catch (ModelNotFoundException $e) {
            return $this->apiError('Order resource not found.', 404);
        } catch (\Throwable $e) {
            return $this->apiError('An error occured trying to get order comments.', 404);
        }

        return $this->apiError('An error occured trying to get order comments.', 404);
    }

    protected function findOrder(
        string $id,
        array $with = [],
        array $columns = [],
        bool $lockForUpdate = false
    ): Order {
        // The fourth argument to findRecordOrFail is a query callback, not
        // a boolean. It must apply the SQL row lock before first().
        return Order::findRecordOrFail(
            $id,
            $with,
            $columns,
            $lockForUpdate ? static function ($query): void {
                $query->lockForUpdate();
            } : null
        );
    }

    protected function resolveOrderConfig(array $input): ?OrderConfig
    {
        return OrderConfig::resolveFromIdentifier($input);
    }

    protected function resolveServiceQuote(Request $request): ?ServiceQuote
    {
        return ServiceQuote::resolveFromRequest($request);
    }

    protected function newPayload(): Payload
    {
        return new Payload();
    }

    protected function findDriverByPublicId(?string $publicId): ?Driver
    {
        return Driver::where(['public_id' => $publicId, 'company_uuid' => session('company')])->first();
    }

    protected function findDriverByUuid(?string $uuid): ?Driver
    {
        return Driver::where('uuid', $uuid)->withoutGlobalScopes()->first();
    }

    protected function findPayloadByUuid(?string $uuid): ?Payload
    {
        return Payload::where('uuid', $uuid)->withoutGlobalScopes()->with(['waypoints', 'waypointMarkers', 'entities'])->first();
    }

    protected function sessionCompany(): ?string
    {
        return session('company');
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        return Utils::getUuid($table, $where, $options);
    }

    protected function getModelClassName(mixed $tableOrModel): ?string
    {
        return Utils::getModelClassName($tableOrModel);
    }

    protected function newCustomerContact(array $attributes): Contact
    {
        return new Contact($attributes);
    }

    protected function firstOrCreateCustomerContact(array $attributes, array $values): Contact
    {
        return Contact::firstOrCreate($attributes, $values);
    }

    protected function defaultCompanyTimezone(): string
    {
        return data_get(Auth::getCompany(), 'timezone', config('app.timezone'));
    }

    protected function createOrder(array $input): Order
    {
        return Order::create($input);
    }

    protected function dispatchFinalizeApiOrderCreation(string $orderUuid, ?string $serviceQuoteUuid, bool $shouldDispatch, string $companyUuid): void
    {
        FinalizeApiOrderCreation::dispatch($orderUuid, $serviceQuoteUuid, $shouldDispatch, $companyUuid)->afterCommit();
    }

    protected function drivingDistanceAndTime(mixed $origin, mixed $destination): mixed
    {
        return Utils::getDrivingDistanceAndTime($origin, $destination);
    }

    protected function createProof(array $input): Proof
    {
        return Proof::create($input);
    }

    protected function createFile(array $input): File
    {
        return File::create($input);
    }

    protected function putStorage(string $disk, string $path, string $contents): void
    {
        Storage::disk($disk)->put($path, $contents);
    }

    protected function proofsForSubject(Order $order, mixed $subject)
    {
        $proofsQuery = Proof::where([
            'company_uuid' => session('company'),
            'order_uuid'   => $order->uuid,
        ]);

        if ($order->uuid !== $subject->uuid) {
            $proofsQuery->where('subject_uuid', $subject->uuid);
        }

        return $proofsQuery->get();
    }

    protected function entityEditingSettings(): mixed
    {
        return Setting::where('key', 'fleet-ops.entity-editing-settings')->value('value');
    }

    protected function orderResource(Order $order)
    {
        return new OrderResource($order);
    }

    protected function deletedOrderResource(Order $order)
    {
        return new DeletedResource($order);
    }

    protected function proofResource(Proof $proof)
    {
        return new ProofResource($proof);
    }

    protected function proofResourceCollection($proofs)
    {
        return ProofResource::collection($proofs);
    }

    protected function commentResourceCollection($comments)
    {
        return CommentResource::collection($comments);
    }

    protected function jsonResponse(mixed $payload, int $status = 200)
    {
        return response()->json($payload, $status);
    }

    protected function apiError(string $message, int $status = 400)
    {
        return response()->apiError($message, $status);
    }
}
