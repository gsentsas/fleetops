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

class FleetOpsNavigatorPairingV2Fake extends \Fleetbase\FleetOps\Support\NavigatorPairingV2Service
{
    public bool $proofValid = false;
    public bool $approved = false;
    public ?ApiCredential $issuedCredential = null;

    public function begin(User $issuer, string $pem): array
    {
        return [
            'pairingSessionId' => str_repeat('c', 64),
            'companyUuid' => $issuer->company_uuid,
            'publicKeyFingerprint' => hash('sha256', $pem),
            'challenge' => str_repeat('d', 64),
            'challengeExpiresUnix' => time() + 120,
            'expiresUnix' => time() + 300,
        ];
    }

    public function prove(string $sessionId, string $signatureBase64): bool
    {
        return $this->proofValid = $sessionId === str_repeat('c', 64) && $signatureBase64 === 'synthetic-valid';
    }

    public function approve(User $actor, string $sessionId, string $deviceConfirmationCode): bool
    {
        return $this->approved = $this->proofValid && $deviceConfirmationCode === str_repeat('a', 12)
            && $actor->type === 'admin' && $actor->company_uuid === 'company-uuid'
            && $sessionId === str_repeat('c', 64);
    }

    public function pendingForIssuer(User $actor): array
    {
        return $actor->type === 'admin' && $actor->company_uuid === 'company-uuid' && $this->proofValid
            && !$this->approved
            ? [[
                'pairingSessionId' => str_repeat('c', 64),
                'publicKeyFingerprint' => str_repeat('a', 64),
                'deviceConfirmationCode' => str_repeat('a', 12),
                'expiresUnix' => time() + 120,
                'verified' => true,
            ]]
            : [];
    }

    public function status(string $sessionId): ?array
    {
        return $sessionId === str_repeat('c', 64)
            ? ['verified' => $this->proofValid, 'approved' => $this->approved,
                'claimChallenge' => $this->approved ? str_repeat('e', 64) : null,
                'claimExpiresUnix' => $this->approved ? time() + 120 : null]
            : null;
    }

    public function claim(string $sessionId, string $signatureBase64): ?ApiCredential
    {
        return $this->proofValid && $this->approved && $sessionId === str_repeat('c', 64)
            && $signatureBase64 === 'synthetic-final'
            ? $this->issuedCredential
            : null;
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
    public FleetOpsNavigatorPairingV2Fake $pairing;

    protected function navigatorPairingV2(): \Fleetbase\FleetOps\Support\NavigatorPairingV2Service
    {
        return $this->pairing ??= new FleetOpsNavigatorPairingV2Fake();
    }


    public bool $v2Enabled = true;

    protected function navigatorV2Enabled(): bool
    {
        return $this->v2Enabled;
    }

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


function fleetopsNavigatorV2PublicKey(): string
{
    static $pem;
    if (!$pem) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
    }
    return $pem;
}

function fleetopsNavigatorV2Post(string $route, array $body, array $query = []): Request
{
    return Request::create(
        $route . ($query ? '?' . http_build_query($query) : ''),
        'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR)
    );
}

function fleetopsNavigatorV2JsonRequest(string $token, array $query = []): Request
{
    return fleetopsNavigatorV2Post('/navigator/pairings/redeem',
        ['pairingSecret' => $token, 'publicKeyPem' => fleetopsNavigatorV2PublicKey()], $query);
}

test('navigator v2 is disabled by default until explicit enablement', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->v2Enabled = false;
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());

    expect($controller->issuePairing(Request::create('/navigator/pairings', 'POST'))->getStatusCode())->toBe(403)
        ->and($controller->redeemPairing(fleetopsNavigatorV2JsonRequest(str_repeat('a', 64)))->getStatusCode())->toBe(403)
        ->and($controller->claimPairing(fleetopsNavigatorV2Post('/navigator/pairings/claim', []))->getStatusCode())->toBe(403);
});

test('navigator v2 refuses legacy GET pairing and old issuance without credentials', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->apiCredential = fleetopsNavigatorCredential();

    $response = $controller->linkApp(Request::create('/navigator/link-app?token=' . str_repeat('a', 64)));
    $issueOld = $controller->getLinkAppUrl();

    expect($response->getStatusCode())->toBe(403)
        ->and($issueOld->getStatusCode())->toBe(403)
        ->and($controller->redirects)->toBe([])
        ->and($controller->credentialLookups)->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('navigator v2 issues only via authorized POST and never returns a bearer URL', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->tokens = new FleetOpsNavigatorTokenFake();

    $result = $controller->issuePairing(Request::create('/navigator/pairings', 'POST'));
    $body = $result->getData(true);

    expect($result->getStatusCode())->toBe(200)
        ->and($body['pairingSecret'])->toHaveLength(64)
        ->and($body['expiresInSeconds'])->toBe(300)
        ->and(array_key_exists('linkUrl', $body))->toBeFalse()
        ->and($controller->tokens->issuedTo)->toBe([['user-uuid', 'company-uuid']])
        ->and($result->headers->get('Cache-Control'))->toContain('no-store');
});

test('navigator v2 rejects unauthenticated issuance', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = null;
    $response = $controller->issuePairing(Request::create('/navigator/pairings', 'POST'));
    expect($response->getStatusCode())->toBe(403);
});

test('navigator v2 QR redeem creates only pending session without credential or Location', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->apiCredential = fleetopsNavigatorCredential('flb_test_device_scoped');
    $controller->tokens = new FleetOpsNavigatorTokenFake();
    $controller->tokens->validTokens[str_repeat('a', 64)] = $controller->adminUser;

    $result = $controller->redeemPairing(fleetopsNavigatorV2JsonRequest(str_repeat('a', 64)));
    $body = $result->getData(true);

    expect($result->getStatusCode())->toBe(200)
        ->and($body['pairingSessionId'])->toHaveLength(64)
        ->and(array_key_exists('credential', $body))->toBeFalse()
        ->and($result->headers->get('Location'))->toBeNull()
        ->and($result->headers->get('Cache-Control'))->toContain('no-store')
        ->and($controller->redirects)->toBe([])
        ->and($controller->credentialLookups)->toBe([]);

    $replay = $controller->redeemPairing(fleetopsNavigatorV2JsonRequest(str_repeat('a', 64)));
    expect($replay->getStatusCode())->toBe(403);
});

test('navigator v2 rejects query, non-json, malformed QR and wrong public key', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->tokens = new FleetOpsNavigatorTokenFake();

    $requests = [
        fleetopsNavigatorV2JsonRequest(str_repeat('a', 64), ['token' => 'bad']),
        fleetopsNavigatorV2JsonRequest('invalid'),
        Request::create('/navigator/pairings/redeem', 'POST', ['pairingSecret' => str_repeat('a', 64)]),
        fleetopsNavigatorV2Post('/navigator/pairings/redeem', []),
        fleetopsNavigatorV2Post('/navigator/pairings/redeem',
            ['pairingSecret' => str_repeat('a', 64), 'publicKeyPem' => 'not a public key']),
    ];

    foreach ($requests as $request) {
        expect($controller->redeemPairing($request)->getStatusCode())->toBe(403);
    }
    expect($controller->credentialLookups)->toBe([]);
});

test('navigator v2 refuses claim before proof and admin approval', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->pairing = new FleetOpsNavigatorPairingV2Fake();
    $controller->pairing->issuedCredential = fleetopsNavigatorCredential('flb_test_scoped');
    $claim = fleetopsNavigatorV2Post('/navigator/pairings/claim',
        ['pairingSessionId' => str_repeat('c', 64), 'signature' => 'synthetic-final']);

    expect($controller->claimPairing($claim)->getStatusCode())->toBe(403);
    $proof = fleetopsNavigatorV2Post('/navigator/pairings/proof',
        ['pairingSessionId' => str_repeat('c', 64), 'signature' => 'synthetic-valid']);
    expect($controller->provePairing($proof)->getStatusCode())->toBe(200)
        ->and($controller->claimPairing($claim)->getStatusCode())->toBe(403);

    $approval = fleetopsNavigatorV2Post('/navigator/pairings/approve',
        ['pairingSessionId' => str_repeat('c', 64), 'deviceConfirmationCode' => str_repeat('a', 12)]);
    expect($controller->approvePairing($approval)->getStatusCode())->toBe(200)
        ->and($controller->claimPairing($claim)->getStatusCode())->toBe(200);
});

test('navigator v2 approval requires authorized administrator', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->pairing = new FleetOpsNavigatorPairingV2Fake();
    $controller->pairing->proofValid = true;
    $approval = fleetopsNavigatorV2Post('/navigator/pairings/approve',
        ['pairingSessionId' => str_repeat('c', 64), 'deviceConfirmationCode' => str_repeat('a', 12)]);
    expect($controller->approvePairing($approval)->getStatusCode())->toBe(403);
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->adminUser->type = 'driver';
    expect($controller->approvePairing($approval)->getStatusCode())->toBe(403);
});

test('navigator v2 admin can only list issuer scoped verified sessions', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->pairing = new FleetOpsNavigatorPairingV2Fake();
    $body = $controller->pendingPairings(fleetopsNavigatorV2Post(
        '/navigator/pairings/admin-pending', []
    ))->getData(true);
    expect($body['pendingPairings'])->toBe([]);
    $controller->pairing->proofValid = true;
    $body = $controller->pendingPairings(fleetopsNavigatorV2Post(
        '/navigator/pairings/admin-pending', []
    ))->getData(true);
    expect($body['pendingPairings'])->toHaveCount(1)
        ->and($body['pendingPairings'][0]['deviceConfirmationCode'])->toBe(str_repeat('a', 12));
    $controller->adminUser = null;
    expect($controller->pendingPairings(fleetopsNavigatorV2Post(
        '/navigator/pairings/admin-pending', []
    ))->getStatusCode())->toBe(403);
});

test('navigator v2 admin cannot approve with wrong device confirmation code', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(fleetopsNavigatorCompany());
    $controller->pairing = new FleetOpsNavigatorPairingV2Fake();
    $controller->pairing->proofValid = true;
    $wrong = fleetopsNavigatorV2Post('/navigator/pairings/approve', [
        'pairingSessionId' => str_repeat('c', 64),
        'deviceConfirmationCode' => str_repeat('b', 12),
    ]);
    expect($controller->approvePairing($wrong)->getStatusCode())->toBe(403)
        ->and($controller->pairing->approved)->toBeFalse();
});

test('navigator v2 status has no credential and only exposes claim challenge after approval', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->pairing = new FleetOpsNavigatorPairingV2Fake();
    $status = fleetopsNavigatorV2Post('/navigator/pairings/status',
        ['pairingSessionId' => str_repeat('c', 64)]);
    $pending = $controller->pairingStatus($status)->getData(true);
    expect($pending['approved'])->toBeFalse()
        ->and($pending['claimChallenge'])->toBeNull()
        ->and(array_key_exists('credential', $pending))->toBeFalse();

    $controller->pairing->proofValid = true;
    $controller->pairing->approved = true;
    $approved = $controller->pairingStatus($status)->getData(true);
    expect($approved['approved'])->toBeTrue()
        ->and($approved['claimChallenge'])->toHaveLength(64)
        ->and(array_key_exists('credential', $approved))->toBeFalse();
});

test('navigator v2 keeps current organization token auth isolated from pairing', function () {
    $controller = new FleetOpsNavigatorControllerProbe();
    $controller->apiCredential = fleetopsNavigatorCredential('flb_test_key');
    $controller->apiCredential->purpose = null;
    $controller->organization = fleetopsNavigatorCompany();

    $request = Request::create('/navigator/current-organization', 'GET', [], [], [],
        ['HTTP_AUTHORIZATION' => 'Bearer flb_test_key']);
    $result = $controller->getCurrentOrganization($request);
    expect($result)->toBeInstanceOf(Organization::class);
});
