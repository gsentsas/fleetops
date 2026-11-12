<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
use Fleetbase\FleetOps\Support\NavigatorCredentialService;
use Fleetbase\FleetOps\Support\NavigatorPairingV2Service;
use Fleetbase\Support\NavigatorCredentialScope;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NavigatorController extends Controller
{
    use AuthorizesMethods;
    /**
     * How long a Navigator link issued from the console stays valid.
     */
    public const LINK_TTL_MINUTES = 5;

    public function __construct()
    {
        $this->authorizeMethods([
            'getLinkAppUrl' => 'admin',
            'issuePairing' => 'admin',
            'approvePairing' => 'admin',
            'pendingPairings' => 'admin',
            'revokePairing' => 'admin',
            'rotatePairing' => 'admin',
        ]);
    }

    /**
     * V2 cutover: legacy query-bearing Navigator links are never redeemed.
     * This endpoint is deliberately left fail-closed for existing bookmarks.
     */
    public function linkApp(Request $request)
    {
        return response()->error('Navigator pairing requires a recent app. Please update Navigator.', 403)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Issues a one-time pairing secret to the authorized company admin.
     *
     * Navigator must scan the QR into the app and send the secret in a POST
     * JSON body; never navigate a browser to a URL carrying the secret.
     */
    public function issuePairing(Request $request)
    {
        // Inactive until the mobile device-binding and full QA gates pass.
        if (!$this->navigatorV2Enabled()) {
            return response()->error('Navigator pairing V2 is not enabled.', 403);
        }

        $issuer = $this->navigatorLinkIssuer();

        if (!$issuer || $issuer->type !== 'admin' || $issuer->status !== 'active') {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }

        try {
            $secret = $this->navigatorLinkTokens()->issue($issuer);
        } catch (\RuntimeException $exception) {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }

        return response()->json([
            'pairingSecret' => $secret,
            'expiresInSeconds' => self::LINK_TTL_MINUTES * 60,
        ])->header('Cache-Control', 'no-store')
          ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A QR bearer can create only a PENDING pairing, never an API credential.
     * The device must prove its private key and an admin must approve first.
     */
    public function redeemPairing(Request $request)
    {
        if (!$this->navigatorV2Enabled()) {
            return response()->error('Navigator pairing V2 is not enabled.', 403);
        }
        if ($request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing request is invalid.', 403);
        }
        $secret = $request->json('pairingSecret');
        $publicKey = $request->json('publicKeyPem');
        if (!is_string($secret) || strlen($secret) !== 64 || !ctype_xdigit($secret)
            || strtolower($secret) !== $secret || !is_string($publicKey)
            || NavigatorPairingV2Service::validatedPublicKey($publicKey) === null) {
            return response()->error('Navigator pairing request is invalid.', 403);
        }

        try {
            $pending = $this->navigatorLinkTokens()->redeemWith(
                $secret,
                fn (User $issuer) => $this->navigatorPairingV2()->begin($issuer, $publicKey)
            );
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }
        if (!is_array($pending) || !isset($pending['pairingSessionId'])) {
            return response()->error('Navigator pairing request is invalid or expired.', 403);
        }
        return response()->json($pending)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function provePairing(Request $request)
    {
        if (!$this->navigatorV2Enabled() || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing is not available.', 403);
        }
        $session = $request->json('pairingSessionId');
        $signature = $request->json('signature');
        if (!is_string($session) || !preg_match('/^[a-f0-9]{64}$/D', $session)
            || !is_string($signature)) {
            return response()->error('Invalid Navigator proof.', 403);
        }
        try {
            $valid = $this->navigatorPairingV2()->prove($session, $signature);
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }
        if (!$valid) {
            return response()->error('Invalid or expired Navigator proof.', 403);
        }
        return response()->json(['verified' => true])->header('Cache-Control', 'no-store');
    }

    /**
     * Protected POST: discover only verified, unapproved pairing sessions
     * created by this exact issuer. No transfer QR or credentials returned.
     */
    public function pendingPairings(Request $request)
    {
        if (!$this->navigatorV2Enabled() || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing is not available.', 403);
        }
        $actor = $this->navigatorLinkIssuer();
        if (!$actor || $actor->type !== 'admin' || $actor->status !== 'active') {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }
        try {
            $sessions = $this->navigatorPairingV2()->pendingForIssuer($actor);
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }

        return response()->json(['pendingPairings' => $sessions])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function approvePairing(Request $request)
    {
        if (!$this->navigatorV2Enabled() || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing is not available.', 403);
        }
        $session = $request->json('pairingSessionId');
        $confirmation = $request->json('deviceConfirmationCode');
        $actor = $this->navigatorLinkIssuer();
        if (!is_string($session) || !preg_match('/^[a-f0-9]{64}$/D', $session)
            || !is_string($confirmation) || !preg_match('/^[a-f0-9]{12}$/D', $confirmation)
            || !$actor || $actor->type !== 'admin' || $actor->status !== 'active') {
            return response()->error('Navigator approval not authorized.', 403);
        }
        try {
            $approved = $this->navigatorPairingV2()->approve($actor, $session, $confirmation);
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }
        if (!$approved) {
            return response()->error('Navigator approval not authorized.', 403);
        }
        return response()->json(['approved' => true])->header('Cache-Control', 'no-store');
    }

    public function pairingStatus(Request $request)
    {
        if (!$this->navigatorV2Enabled() || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing is not available.', 403);
        }
        $session = $request->json('pairingSessionId');
        if (!is_string($session) || !preg_match('/^[a-f0-9]{64}$/D', $session)) {
            return response()->error('Navigator pairing request invalid.', 403);
        }
        try {
            $status = $this->navigatorPairingV2()->status($session);
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }
        if ($status === null) {
            return response()->error('Navigator pairing expired.', 403);
        }
        return response()->json($status)->header('Cache-Control', 'no-store');
    }

    /**
     * Final credential is available only after an authorized admin approval
     * and a SECOND cryptographic challenge signed by the same device key.
     */
    public function claimPairing(Request $request)
    {
        if (!$this->navigatorV2Enabled() || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator pairing is not available.', 403);
        }
        $session = $request->json('pairingSessionId');
        $signature = $request->json('signature');
        if (!is_string($session) || !preg_match('/^[a-f0-9]{64}$/D', $session)
            || !is_string($signature)) {
            return response()->error('Navigator pairing request invalid.', 403);
        }
        try {
            $credential = $this->navigatorPairingV2()->claim($session, $signature);
        } catch (\Throwable $exception) {
            return response()->error('Navigator pairing temporarily unavailable.', 503);
        }
        if (!$credential instanceof ApiCredential) {
            return response()->error('Navigator pairing is not yet authorized.', 403);
        }
        return response()->json([
            'credential' => $credential->key,
            'host' => $this->secureRootUrl(),
            'socketcluster_host' => $this->socketClusterHost(),
            'socketcluster_port' => $this->socketClusterPort(),
            'socketcluster_secure' => $this->socketClusterSecure(),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Legacy issuance GET is intentionally disabled: it would return an
     * unprotected bearer link in an HTTP URL, compromising error logs.
     */
    public function getLinkAppUrl()
    {
        return response()->error('Navigator pairing requires the V2 client.', 403)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function revokePairing(string $uuid)
    {
        $actor = $this->navigatorLinkIssuer();
        if (!$actor) {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }
        try {
            app(NavigatorCredentialService::class)->revoke($actor, $uuid);
        } catch (\RuntimeException $exception) {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }

        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store');
    }

    public function rotatePairing(string $uuid)
    {
        // Inactive until the mobile device-binding and full QA gates pass.
        if (!$this->navigatorV2Enabled()) {
            return response()->error('Navigator pairing V2 is not enabled.', 403);
        }

        $actor = $this->navigatorLinkIssuer();
        if (!$actor) {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }
        try {
            $token = DB::transaction(function () use ($actor, $uuid) {
                app(NavigatorCredentialService::class)->revoke($actor, $uuid);
                return $this->navigatorLinkTokens()->issue($actor->fresh());
            });
        } catch (\RuntimeException $exception) {
            return response()->error('Navigator pairing operation is not authorized.', 403);
        }

        return response()->json([
            'pairingSecret' => $token,
            'expiresInSeconds' => self::LINK_TTL_MINUTES * 60,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Retrieves the current organization based on the bearer token (API key or secret).
     *
     * Determines the correct database connection based on the API key format,
     * retrieves the associated API credential, and returns the organization resource.
     *
     * @return \Illuminate\Http\JsonResponse|\App\Http\Resources\Organization
     */
    public function getCurrentOrganization(Request $request)
    {
        $token       = $request->bearerToken();
        $isSecretKey = Str::startsWith($token, '$');

        // Depending on API key format set the connection to find credential on
        $connection = Str::startsWith($token, 'flb_test_') ? 'sandbox' : 'mysql';

        // Find the API Credential record
        $apiCredential = $this->findApiCredentialForToken($token, $connection, $isSecretKey);

        // Handle no api credential found
        if (!$apiCredential || $apiCredential->trashed() || $apiCredential->hasExpired()
            || (NavigatorCredentialScope::isNavigator($apiCredential) && !NavigatorCredentialScope::valid($apiCredential))) {
            return response()->error('No API key found to fetch company details with.');
        }

        // Get the organization owning the API key
        $organization = $this->findOrganization($apiCredential->company_uuid);

        return new Organization($organization);
    }

    /**
     * Retrieves the driver onboarding settings from the system configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings()
    {
        $onBoardSettings = $this->driverOnboardSettings();

        return response()->json($onBoardSettings);
    }

    protected function navigatorPairingV2(): NavigatorPairingV2Service
    {
        return app(NavigatorPairingV2Service::class);
    }

    protected function navigatorV2Enabled(): bool
    {
        // Fail closed even if routes are registered by a candidate build.
        return config('fleetops.navigator.pairing_v2_enabled', false) === true;
    }

    protected function navigatorLinkIssuer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function navigatorLinkTokens(): NavigatorLinkTokenService
    {
        return app(NavigatorLinkTokenService::class);
    }

    protected function validNavigatorCredential(ApiCredential $credential): bool
    {
        return NavigatorCredentialScope::valid($credential);
    }

    // Retained for scoped internal callers; a missing company fails closed.
    protected function findAdminUser(?string $companyUuid = null): ?User
    {
        if (!$companyUuid) {
            return null;
        }

        return User::query()
            ->where('type', 'admin')
            ->where('company_uuid', $companyUuid)
            ->first();
    }

    protected function firstOrCreateNavigatorCredential(User $adminUser): ApiCredential
    {
        return app(NavigatorCredentialService::class)->issue($adminUser);
    }

    protected function secureRootUrl(): string
    {
        return url()->secure('/');
    }

    protected function navigatorAppIdentifier(): string
    {
        return config('fleetops.navigator.app_identifier', 'io.fleetbase.navigator');
    }

    protected function socketClusterHost(): string
    {
        return env('SOCKETCLUSTER_HOST', 'socket');
    }

    protected function socketClusterPort(): int|string
    {
        return env('SOCKETCLUSTER_PORT', 8000);
    }

    protected function socketClusterSecure(): bool
    {
        return Utils::castBoolean(env('SOCKETCLUSTER_SECURE', false));
    }

    protected function redirectAway(string $url)
    {
        return Redirect::away($url)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    protected function findApiCredentialForToken(?string $token, string $connection, bool $isSecretKey): ?ApiCredential
    {
        return ApiCredential::on($connection)
            ->where(function ($query) use ($isSecretKey, $token) {
                if ($isSecretKey) {
                    $query->where('secret', $token);
                } else {
                    $query->where('key', $token);
                }
            })
            ->with(['company.owner'])
            ->withoutGlobalScopes()
            ->first();
    }

    protected function findOrganization(string $companyUuid): ?Company
    {
        return Company::where('uuid', $companyUuid)->first();
    }

    protected function driverOnboardSettings(): mixed
    {
        return Setting::where('key', 'fleet-ops.driver-onboard')->value('value');
    }

}
