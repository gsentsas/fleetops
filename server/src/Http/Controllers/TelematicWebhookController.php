<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\WebhookReplayLedger;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class TelematicWebhookController.
 *
 * Handles webhook ingestion from telematics providers.
 *
 * Standard providers and custom ingest run, in this order: HTTP method,
 * signature present, integration, signature valid, idempotency header and
 * body size, then ONE database transaction holding the replay receipt, the
 * business writes and the receipt completion, then the HTTP response.
 * Telemetry providers (AFAQY, Safee...) keep their own pipeline.
 */
class TelematicWebhookController extends Controller
{
    /** Seconds a provider should wait before redelivering a 503'd webhook. */
    public const RETRY_AFTER_SECONDS = 30;

    /** Deadlock / lock-timeout retries of the whole receipt transaction. */
    private const TRANSACTION_ATTEMPTS = 2;

    protected TelematicProviderRegistry $registry;
    protected TelematicService $service;

    /**
     * Kept for constructor compatibility only; no longer consulted. Its cache
     * key is global ("idempotency:<key>"), so one tenant's key marked another
     * tenant's delivery as a duplicate, and it expires or is evicted. Replay
     * protection is the tenant-scoped, durable WebhookReplayLedger.
     *
     * @deprecated
     */
    protected IdempotencyManager $idempotency;

    public function __construct(
        TelematicProviderRegistry $registry,
        TelematicService $service,
        IdempotencyManager $idempotency,
    ) {
        $this->registry    = $registry;
        $this->service     = $service;
        $this->idempotency = $idempotency;
    }

    /**
     * Handle provider webhook.
     */
    public function handle(Request $request, string $providerKey): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required.'], 405);
        }

        if ($this->registry->resolve($providerKey) instanceof \Fleetbase\FleetOps\Contracts\TelemetryProviderInterface) {
            return app(TelematicPositionWebhookController::class)->handle($request, app(\Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox::class), $providerKey);
        }

        // Standard providers must present a signature before integration lookup.
        $signature = $request->header('X-Webhook-Signature');
        if (!is_string($signature) || trim($signature) === '') {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $correlationId = Str::uuid()->toString();

        Log::info('Webhook received', [
            'correlation_id'      => $correlationId,
            'provider'            => $providerKey,
            'has_signature'       => $request->headers->has('X-Webhook-Signature'),
            'has_idempotency_key' => $request->headers->has('X-Idempotency-Key'),
        ]);

        // Get provider
        $provider = $this->registry->resolve($providerKey);

        // Find telematic for this provider. Native provider payloads are
        // resolved by provider identifiers first; Fleetbase URL/header ids are
        // optional configuration affordances where provider dashboards allow it.
        $telematicId = $request->query('telematic') ?? $request->query('integration') ?? $request->header('X-Fleetbase-Telematic');
        $telematic   = $this->service->resolveWebhookTelematic($providerKey, $request->all(), $request->headers->all(), $telematicId);

        if (!$telematic && !$telematicId && $signature) {
            $signatureMatches = Telematic::where('provider', $providerKey)
                ->get()
                ->filter(fn (Telematic $candidate) => $provider->validateWebhookSignature($request->getContent(), $signature, $this->service->getCredentials($candidate)));

            if ($signatureMatches->count() === 1) {
                $telematic = $signatureMatches->first();
            } elseif ($signatureMatches->count() > 1) {
                Log::warning('Ambiguous telematic webhook signature match', [
                    'correlation_id' => $correlationId,
                    'provider'       => $providerKey,
                    'matches'        => $signatureMatches->count(),
                ]);

                return response()->json(['error' => 'Ambiguous telematic integration'], 409);
            }
        }

        if (!$telematic) {
            Log::warning('Unable to resolve telematic for provider webhook', [
                'correlation_id'     => $correlationId,
                'provider'           => $providerKey,
                'has_integration_id' => (bool) $telematicId,
            ]);

            return response()->json(['error' => 'Unable to resolve telematic integration'], 422);
        }

        // Validate signature
        $credentials = $this->service->getCredentials($telematic);

        if (!$provider->validateWebhookSignature($request->getContent(), $signature, $credentials)) {
            Log::warning('Invalid webhook signature', [
                'correlation_id' => $correlationId,
                'provider'       => $providerKey,
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Authenticated from here on: replay protection, then business writes.
        return $this->processAuthenticatedDelivery($request, $telematic, 'provider', $correlationId, function () use ($request, $provider, $telematic, $correlationId, $providerKey): array {
            $result  = $provider->processWebhook($request->all(), $request->headers->all());
            $devices = $result['devices'] ?? [];
            $events  = $result['events'] ?? [];
            $sensors = $result['sensors'] ?? [];

            $devicesByExternalId = [];

            // Link devices
            foreach ($devices as $deviceData) {
                try {
                    $device     = $this->service->linkDevice($telematic, $deviceData);
                    $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? $deviceData['unit_id'] ?? $deviceData['vehicle_id'] ?? $deviceData['imei'] ?? null;
                    if ($externalId) {
                        $devicesByExternalId[$externalId] = $device;
                    }
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping webhook device without provider identity', [
                        'correlation_id' => $correlationId,
                        'provider'       => $providerKey,
                    ]);
                }
            }

            foreach ($events as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? $eventData['unit_id'] ?? $eventData['vehicle_id'] ?? $eventData['imei'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($sensors as $sensorData) {
                try {
                    $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? $sensorData['unit_id'] ?? $sensorData['vehicle_id'] ?? $sensorData['imei'] ?? null;
                    $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping webhook sensor without provider or device identity', [
                        'correlation_id' => $correlationId,
                        'provider'       => $providerKey,
                    ]);
                }
            }

            return ['devices_count' => count($devices), 'events_count' => count($events), 'sensors_count' => count($sensors)];
        }, ['status' => 'processed'], 'Processing failed');
    }

    /**
     * Handle custom provider ingest.
     */
    public function ingest(Request $request, string $id): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required.'], 405);
        }

        // Reject unauthenticated requests before exposing whether an integration exists.
        $signature = $request->header('X-Webhook-Signature');
        if (!is_string($signature) || trim($signature) === '') {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $telematic   = Telematic::where('public_id', $id)->orWhere('uuid', $id)->firstOrFail();
        $credentials = $this->service->getCredentials($telematic);
        $secret      = $credentials['webhook_secret'] ?? null;
        if (!is_string($secret) || trim($secret) === '') {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $correlationId = Str::uuid()->toString();

        Log::info('Custom ingest received', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $id,
        ]);

        // Authenticated from here on: replay protection, then business writes.
        return $this->processAuthenticatedDelivery($request, $telematic, 'custom', $correlationId, function () use ($request, $telematic, $correlationId): array {
            $devicesByExternalId = [];

            // Process devices
            if ($request->has('devices')) {
                foreach ($request->input('devices') as $deviceData) {
                    try {
                        $device     = $this->service->linkDevice($telematic, $deviceData);
                        $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? $deviceData['unit_id'] ?? $deviceData['vehicle_id'] ?? $deviceData['imei'] ?? null;
                        if ($externalId) {
                            $devicesByExternalId[$externalId] = $device;
                        }
                    } catch (\Illuminate\Validation\ValidationException) {
                        Log::warning('Skipping custom ingest device without provider identity', [
                            'correlation_id' => $correlationId,
                        ]);
                    }
                }
            }

            foreach ($request->input('events', []) as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? $eventData['unit_id'] ?? $eventData['vehicle_id'] ?? $eventData['imei'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($request->input('sensors', []) as $sensorData) {
                try {
                    $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? $sensorData['unit_id'] ?? $sensorData['vehicle_id'] ?? $sensorData['imei'] ?? null;
                    $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping custom ingest sensor without provider or device identity', [
                        'correlation_id' => $correlationId,
                    ]);
                }
            }

            return [
                'devices_count' => count($request->input('devices', [])),
                'events_count'  => count($request->input('events', [])),
                'sensors_count' => count($request->input('sensors', [])),
            ];
        }, ['status' => 'ingested'], 'Ingest failed');
    }

    /**
     * The ledger MUST use the connection the business models write to, so the
     * receipt and the writes share one transaction.
     */
    protected function replayLedger(Telematic $telematic): WebhookReplayLedger
    {
        return new WebhookReplayLedger($telematic->getConnectionName());
    }

    /**
     * Replay-protected processing of an AUTHENTICATED delivery. The receipt,
     * the business writes and the receipt completion commit or roll back
     * together; the service defers non-database effects until COMMIT.
     *
     * @param \Closure():array<string,int> $effects
     * @param array<string,string>         $successBody
     */
    private function processAuthenticatedDelivery(Request $request, Telematic $telematic, string $endpoint, string $correlationId, \Closure $effects, array $successBody, string $failureError): JsonResponse
    {
        $rawBody   = $request->getContent();
        $clientKey = $request->headers->get('X-Idempotency-Key');
        $audit     = ['correlation_id' => $correlationId, 'provider' => $telematic->provider, 'telematic_uuid' => $telematic->uuid, 'endpoint' => $endpoint];

        if ($clientKey !== null && !WebhookReplayLedger::isValidClientKey($clientKey)) {
            Log::warning('Webhook rejected: invalid idempotency key', $audit);

            return response()->json(['error' => 'Invalid idempotency key'], 400);
        }

        if (strlen($rawBody) > WebhookReplayLedger::MAX_BODY_BYTES) {
            Log::warning('Webhook rejected: payload too large', $audit + ['bytes' => strlen($rawBody)]);

            return response()->json(['error' => 'Payload too large'], 413);
        }

        $scope = [
            'company_uuid'     => (string) $telematic->company_uuid,
            'provider'         => (string) $telematic->provider,
            'integration_uuid' => (string) $telematic->uuid,
            'endpoint'         => $endpoint,
        ];

        // Remembers which exception came from the business effects (of the
        // last attempt), rethrown unchanged so deadlock retries still apply.
        $business = new \stdClass();
        try {
            $outcome = $this->replayLedger($telematic)->processAtomically($scope, $rawBody, $clientKey, function () use ($effects, $business) {
                $business->failure = null;
                try {
                    return $effects();
                } catch (\Throwable $e) {
                    $business->failure = $e;

                    throw $e;
                }
            }, self::TRANSACTION_ATTEMPTS);
        } catch (\Throwable $e) {
            if (($business->failure ?? null) === $e) {
                // Rolled back with its receipt: the provider's retry is processed again.
                Log::error('Webhook processing failed', $audit + self::failureContext($e));

                return response()->json(['error' => $failureError], 500);
            }
            if ($e instanceof \InvalidArgumentException) {
                // e.g. an integration without a company: never processed unprotected.
                Log::error('Webhook rejected: integration cannot be scoped for replay protection', $audit + self::failureContext($e));

                return response()->json(['error' => $failureError], 500);
            }

            // Ledger or database failure: nothing was committed, fail closed.
            Log::error('Webhook replay ledger unavailable', $audit + self::failureContext($e));

            return $this->retryLater('Webhook temporarily unavailable');
        }

        $audit += ['decision' => $outcome['decision'], 'receipt' => substr($outcome['receipt_key'], 0, 16)];

        switch ($outcome['decision']) {
            case WebhookReplayLedger::PROCESSED:
                Log::info('Webhook processed successfully', $audit + (array) $outcome['result']);

                return response()->json($successBody, 200);

            case WebhookReplayLedger::DUPLICATE_PROCESSED:
                Log::info('Authenticated duplicate webhook', $audit);

                return response()->json(['status' => 'duplicate'], 200);

            case WebhookReplayLedger::PAYLOAD_CONFLICT:
                Log::warning('Webhook rejected: idempotency key reused with a different payload', $audit);

                return response()->json(['error' => 'Idempotency key already used with a different payload'], 409);

            case WebhookReplayLedger::STALE_INDETERMINATE:
                // Only a non-transactional claim can leave this; an operator must reconcile it.
                Log::error('Webhook receipt needs reconciliation', $audit);

                return $this->retryLater('Webhook delivery is being reconciled');

            default:
                Log::info('Webhook delivery already in progress', $audit);

                return $this->retryLater('Webhook delivery already in progress');
        }
    }

    private function retryLater(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 503)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    /**
     * Never log SQL: a QueryException message embeds the statement and its
     * bindings (payload data). Driver codes are enough to diagnose.
     *
     * @return array<string,mixed>
     */
    private static function failureContext(\Throwable $e): array
    {
        if ($e instanceof QueryException || $e instanceof \PDOException) {
            return ['exception' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null, 'driver_code' => $e->errorInfo[1] ?? null];
        }

        return ['exception' => $e::class, 'error' => Str::limit($e->getMessage(), 200)];
    }
}
