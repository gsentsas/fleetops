<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return 'http://localhost/' . ltrim($path, '/');
    }
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\NavigatorController;
use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

// Laravel 12 types its global response() helper; use the package's isolated
// response factory directly for these synthetic controller response contracts.
if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\response')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1; function response() { return \\app(\\Illuminate\\Contracts\\Routing\\ResponseFactory::class); }');
}
if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\url')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1; function url($path = "") { return "http://localhost/" . ltrim($path, "/"); }');
}

class FleetOpsNavigatorTokenFake extends NavigatorLinkTokenService
{
    public array $validTokens = [];
    public array $issuedTo = [];

    public function issue(User $issuer): string
    {
        $this->issuedTo[] = [$issuer->uuid, $issuer->company_uuid];

        return str_repeat('a', 64);
    }

    public function redeem(string $token): ?User
    {
        $issuer = $this->validTokens[$token] ?? null;
        unset($this->validTokens[$token]);

        return $issuer;
    }

    public function redeemWith(string $token, \Closure $issue): mixed
    {
        $issuer = $this->redeem($token);
        return $issuer && $issuer->company ? $issue($issuer) : null;
    }
}

class FleetOpsNavigatorControllerProbe extends NavigatorController
{
    public ?User $adminUser              = null;
    public ?ApiCredential $apiCredential = null;
    public ?Company $organization        = null;
    public mixed $settings               = null;
    public array $redirects              = [];
    public array $credentialLookups      = [];
    public array $organizationLookups    = [];
    public FleetOpsNavigatorTokenFake $tokens;

    protected function navigatorLinkIssuer(): ?User
    {
        return $this->adminUser;
    }

    protected function navigatorLinkTokens(): NavigatorLinkTokenService
    {
        return $this->tokens ??= new FleetOpsNavigatorTokenFake();
    }

    protected function firstOrCreateNavigatorCredential(User $adminUser): ApiCredential
    {
        $this->credentialLookups[] = ['navigator', $adminUser->uuid, $adminUser->company_uuid];

        return $this->apiCredential;
    }

    // Response-contract double; real authorization runs in Fleet integration Rc1NavigatorMySqlTest.
    protected function validNavigatorCredential(ApiCredential $credential): bool
    {
        return $credential->purpose === 'navigator'
            && $credential->company_uuid === $this->adminUser?->company_uuid
            && $credential->issuer_user_uuid === $this->adminUser?->uuid
            && $credential->user_uuid === 'device-uuid'
            && !$credential->trashed() && !$credential->hasExpired();
    }

    protected function secureRootUrl(): string
    {
        return 'https://api.fleetbase.test';
    }

    protected function navigatorAppIdentifier(): string
    {
        return 'io.fleetbase.navigator.test';
    }

    protected function socketClusterHost(): string
    {
        return 'socket.test';
    }

    protected function socketClusterPort(): int|string
    {
        return 7001;
    }

    protected function socketClusterSecure(): bool
    {
        return true;
    }

    protected function redirectAway(string $url)
    {
        $this->redirects[] = $url;

        return new \Illuminate\Http\JsonResponse(['redirect' => $url]);
    }

    protected function findApiCredentialForToken(?string $token, string $connection, bool $isSecretKey): ?ApiCredential
    {
        $this->credentialLookups[] = ['token', $token, $connection, $isSecretKey];

        return $this->apiCredential;
    }

    protected function findOrganization(string $companyUuid): ?Company
    {
        $this->organizationLookups[] = $companyUuid;

        return $this->organization;
    }

    protected function driverOnboardSettings(): mixed
    {
        return $this->settings;
    }
}

function fleetopsNavigatorUser(?Company $company = null): User
{
    $user = new User();
    $user->setRawAttributes([
        'uuid'         => 'user-uuid',
        'company_uuid' => $company?->uuid,
        'type'         => 'admin',
        'status'       => 'active',
    ], true);
    $user->setRelation('company', $company);

    return $user;
}

function fleetopsNavigatorCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes([
        'uuid'      => 'company-uuid',
        'public_id' => 'company_public',
        'name'      => 'Acme Logistics',
    ], true);

    return $company;
}

function fleetopsNavigatorCredential(string $key = 'flb_live_key'): ApiCredential
{
    $credential = new ApiCredential();
    $credential->setDateFormat('Y-m-d H:i:s');
    $credential->setRawAttributes([
        'uuid'         => 'credential-uuid',
        'user_uuid'    => 'device-uuid',
        'issuer_user_uuid' => 'user-uuid',
        'purpose'      => 'navigator',
        'expires_at'   => now()->addDay()->toDateTimeString(),
        'key'          => $key,
        'secret'       => '$secret',
        'company_uuid' => 'company-uuid',
    ], true);

    return $credential;
}

function fleetopsNavigatorTokenQuery(string $value = 'a'): array
{
    return ['token' => str_repeat($value, 64)];
}

test('navigator controller builds android and ios app link redirects', function () {
    $company                   = fleetopsNavigatorCompany();
    $controller                = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser     = fleetopsNavigatorUser($company);
    $controller->apiCredential = fleetopsNavigatorCredential('flb_live_navigator');
    $controller->tokens = new FleetOpsNavigatorTokenFake();
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;
    $controller->tokens->validTokens[str_repeat('b', 64)] = $controller->adminUser;

    $android = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery(), [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Android',
    ]))->getData(true);

    $ios = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery('b'), [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 iPhone',
    ]))->getData(true);

    expect($android['redirect'])->toStartWith('intent://configure?')
        ->and($android['redirect'])->toContain('key=flb_live_navigator')
        ->and($android['redirect'])->toContain('host=https%3A%2F%2Fapi.fleetbase.test')
        ->and($android['redirect'])->toContain('package=io.fleetbase.navigator.test;end')
        ->and($ios['redirect'])->toStartWith('flbnavigator://configure?')
        ->and($ios['redirect'])->toContain('socketcluster_host=socket.test')
        ->and($ios['redirect'])->toContain('socketcluster_port=7001')
        ->and($ios['redirect'])->toContain('socketcluster_secure=1')
        ->and($controller->credentialLookups)->toBe([
            ['navigator', 'user-uuid', 'company-uuid'],
            ['navigator', 'user-uuid', 'company-uuid'],
        ]);
});

test('navigator controller blocks cross-tenant and wrong-subject credentials', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->tokens = new FleetOpsNavigatorTokenFake();
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;

    $credential = fleetopsNavigatorCredential('flb_live_wrong_company');
    $credential->company_uuid = 'other-company';
    $controller->apiCredential = $credential;

    $denied = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery()));

    expect($denied->getStatusCode())->toBe(403)
        ->and($controller->redirects)->toBe([]);

    $controller->tokens->validTokens[str_repeat('b', 64)] = $controller->adminUser;
    $credential->company_uuid = 'company-uuid';
    $credential->user_uuid = 'other-user';

    $deniedSubject = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery('b')));

    expect($deniedSubject->getStatusCode())->toBe(403)
        ->and($controller->redirects)->toBe([]);
});

test('navigator controller refuses expired or revoked credentials before mobile redirect', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->tokens = new FleetOpsNavigatorTokenFake();

    $expired = fleetopsNavigatorCredential('flb_live_expired');
    $expired->expires_at = now()->subMinute();
    $controller->apiCredential = $expired;
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;

    $deniedExpired = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery()));

    expect($deniedExpired->getStatusCode())->toBe(403)
        ->and($controller->redirects)->toBe([]);

    $revoked = fleetopsNavigatorCredential('flb_live_revoked');
    $revoked->setRawAttributes(array_merge($revoked->getAttributes(), [
        'deleted_at' => now()->subMinute()->toDateTimeString(),
    ]), true);
    $controller->apiCredential = $revoked;
    $controller->tokens->validTokens[str_repeat('b', 64)] = $controller->adminUser;

    $deniedRevoked = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery('b')));

    expect($deniedRevoked->getStatusCode())->toBe(403)
        ->and($controller->redirects)->toBe([]);
});

test('navigator controller refuses replay of a previously redeemed link', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->apiCredential = fleetopsNavigatorCredential();
    $controller->tokens = new FleetOpsNavigatorTokenFake();
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;

    $request = Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery());
    $accepted = $controller->linkApp($request);
    $replayed = $controller->linkApp($request);

    expect($accepted->getData(true)['redirect'])->toStartWith('flbnavigator://')
        ->and($replayed->getStatusCode())->toBe(403)
        ->and($controller->credentialLookups)->toHaveCount(1);
});

test('navigator controller returns missing organization error when no admin company is available', function () {
    $controller            = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(null);
    $controller->tokens = new FleetOpsNavigatorTokenFake();
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;

    $response = $controller->linkApp(Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery()));

    expect($response->getStatusCode())->toBe(403);
});

test('navigator controller refuses unsigned, tampered and expired app links before touching credentials', function () {
    $company                   = fleetopsNavigatorCompany();
    $controller                = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser     = fleetopsNavigatorUser($company);
    $controller->apiCredential = fleetopsNavigatorCredential('flb_live_navigator');

    $requests = [
        'unsigned' => Request::create('/navigator/link-app', 'GET'),
        'tampered' => Request::create('/navigator/link-app', 'GET', fleetopsNavigatorTokenQuery('f')),
        'legacy'   => Request::create('/navigator/link-app', 'GET', ['expires' => time() + 600, 'signature' => str_repeat('f', 64)]),
    ];

    foreach ($requests as $request) {
        $response = $controller->linkApp($request);
        expect($response->getData(true))->toBe(['error' => 'This Navigator link is invalid or has expired. Generate a new one from the console.']);
    }

    expect($controller->credentialLookups)->toBe([]);
});

test('navigator controller exposes link url settings and current organization token lookup branches', function () {
    $controller                = new FleetOpsNavigatorControllerProbe();
    $controller->apiCredential = fleetopsNavigatorCredential('flb_test_key');
    $controller->apiCredential->purpose = null;
    $controller->organization  = fleetopsNavigatorCompany();
    $controller->adminUser = fleetopsNavigatorUser($controller->organization);
    $controller->settings      = ['enabled' => true, 'invite_code_required' => false];

    $linkUrl  = $controller->getLinkAppUrl()->getData(true);
    $settings = $controller->getDriverOnboardSettings()->getData(true);

    $testKeyRequest = Request::create('/navigator/current-organization', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer flb_test_key',
    ]);
    $organization = $controller->getCurrentOrganization($testKeyRequest);

    $controller->apiCredential = null;
    $secretRequest             = Request::create('/navigator/current-organization', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer $secret',
    ]);
    $missing = $controller->getCurrentOrganization($secretRequest);

    parse_str((string) parse_url($linkUrl['linkUrl'], PHP_URL_QUERY), $linkQuery);

    expect($linkUrl['linkUrl'])->toStartWith('http://localhost/int/v1/fleet-ops/navigator/link-app?')
        ->and($linkQuery)->toBe(fleetopsNavigatorTokenQuery())
        ->and($controller->tokens->issuedTo)->toBe([['user-uuid', 'company-uuid']])
        ->and($settings)->toBe(['enabled' => true, 'invite_code_required' => false])
        ->and($organization)->toBeInstanceOf(Organization::class)
        ->and($controller->credentialLookups)->toContain(
            ['token', 'flb_test_key', 'sandbox', false],
            ['token', '$secret', 'mysql', true]
        )
        ->and($controller->organizationLookups)->toBe(['company-uuid'])
        ->and($missing->getData(true))->toBe(['error' => 'No API key found to fetch company details with.']);
});
