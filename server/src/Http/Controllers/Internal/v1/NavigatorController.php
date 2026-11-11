<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;

class NavigatorController extends Controller
{
    use AuthorizesMethods;
    /**
     * How long a Navigator link issued from the console stays valid.
     */
    public const LINK_TTL_MINUTES = 30;

    public function __construct()
    {
        $this->authorizeMethods([
            'getLinkAppUrl' => 'admin',
        ]);
    }

    /**
     * Redirects to the Fleetbase Navigator app using a deep link.
     * Automatically detects the platform (iOS or Android) and uses the correct URI scheme.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function linkApp(Request $request)
    {
        // The public redemption endpoint only accepts an opaque, single-use
        // capability bound to the authenticated issuing company's admin.
        // Legacy expires+signature links intentionally fail closed.
        $token = $request->query('token');

        if (!is_string($token)) {
            return response()->error('This Navigator link is invalid or has expired. Generate a new one from the console.', 403);
        }

        $adminUser = $this->navigatorLinkTokens()->redeem($token);

        if (!$adminUser) {
            return response()->error('This Navigator link is invalid or has expired. Generate a new one from the console.', 403);
        }

        if (!$adminUser || !$adminUser->company) {
            return response()->error('Organization for linking not found.');
        }

        $apiCredential = $this->firstOrCreateNavigatorCredential($adminUser);

        // Never redirect with a credential from a different tenant/subject,
        // even if an existing database row or a credential helper is corrupt.
        if (!$apiCredential
            || $apiCredential->company_uuid !== $adminUser->company_uuid
            || $apiCredential->user_uuid !== $adminUser->uuid
            || !is_string($apiCredential->key)
            || $apiCredential->key === '') {
            return response()->error('Navigator credential does not match its issuing organization.', 403);
        }

        $key           = $apiCredential->key;
        $host          = $this->secureRootUrl();
        $socketHost    = $this->socketClusterHost();
        $socketPort    = $this->socketClusterPort();
        $socketSecure  = $this->socketClusterSecure();
        $appIdentifier = $this->navigatorAppIdentifier();

        $deepLinkParams = http_build_query([
            'key'                  => $key,
            'host'                 => $host,
            'socketcluster_host'   => $socketHost,
            'socketcluster_port'   => $socketPort,
            'socketcluster_secure' => $socketSecure,
        ]);

        $userAgent = $request->header('User-Agent');
        if (stripos($userAgent, 'android') !== false) {
            // Android: Use intent:// scheme
            $intentUrl = "intent://configure?$deepLinkParams#Intent;scheme=flbnavigator;package=" . $appIdentifier . ';end';

            return $this->redirectAway($intentUrl);
        }

        // Default to iOS (or fallback): Use flbnavigator://
        $iosUrl = "flbnavigator://configure?$deepLinkParams";

        return $this->redirectAway($iosUrl);
    }

    /**
     * Returns the URL used to link the Fleetbase Navigator app.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLinkAppUrl()
    {
        $issuer = $this->navigatorLinkIssuer();

        if (!$issuer || $issuer->type !== 'admin') {
            return response()->error('Navigator link issuer is not authorized.', 403);
        }

        try {
            $token = $this->navigatorLinkTokens()->issue($issuer);
        } catch (\RuntimeException $exception) {
            return response()->error('Navigator link issuer is not authorized.', 403);
        }

        return response()->json([
            'linkUrl' => url('int/v1/fleet-ops/navigator/link-app') . '?' . http_build_query(['token' => $token]),
        ]);
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
        if (!$apiCredential) {
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

    protected function navigatorLinkIssuer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function navigatorLinkTokens(): NavigatorLinkTokenService
    {
        return app(NavigatorLinkTokenService::class);
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
        return ApiCredential::firstOrCreate(
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company_uuid,
                'name'         => 'NavigationAppLinker',
            ],
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company->uuid,
                'name'         => 'NavigationAppLinker',
            ]
        );
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
        return Redirect::away($url);
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
