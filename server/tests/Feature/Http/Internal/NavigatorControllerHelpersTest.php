<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\NavigatorController;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real bodies of the internal NavigatorController's protected
 * helper methods: admin lookup, navigator credential creation, deep-link
 * configuration values, redirects, api-credential token resolution,
 * organization lookup, and driver onboard settings.
 */
if (!function_exists('url')) {
    function url(?string $path = null)
    {
        if ($path === null) {
            return new class {
                public function secure(string $path = '/'): string
                {
                    return 'https://fleetbase.test' . $path;
                }
            };
        }

        return 'https://fleetbase.test/' . ltrim($path, '/');
    }
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key, $default = null) { return \FleetOpsNavigatorHelpersEnv::$values[$key] ?? $default; }');
}

class FleetOpsNavigatorHelpersEnv
{
    public static array $values = [];
}

class FleetOpsNavigatorHelpersRedirectFake
{
    public array $urls = [];

    public function away(string $url): string
    {
        $this->urls[] = $url;

        return 'redirected:' . $url;
    }
}

class FleetOpsInternalNavigatorHelpersProbe extends NavigatorController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(NavigatorController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalNavigatorHelpersBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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
    $tables = [
        'users'           => ['uuid', 'public_id', 'company_uuid', 'type', 'name', 'email', 'phone'],
        'companies'       => ['uuid', 'public_id', 'name', 'owner_uuid'],
        'api_credentials' => ['uuid', 'public_id', 'user_uuid', 'company_uuid', 'name', 'key', 'secret', 'expires_at', 'last_used_at'],
        'settings'        => ['key', 'value'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('admin lookup and navigator credential helpers execute against the database', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'admin']);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $admin = $probe->callProtected('findAdminUser', ['company-1']);
    expect($admin)->toBeInstanceOf(User::class)
        ->and($admin->uuid)->toBe('user-1');

    $credential = $probe->callProtected('firstOrCreateNavigatorCredential', [$admin]);
    expect($credential)->toBeInstanceOf(ApiCredential::class)
        ->and($connection->table('api_credentials')->count())->toBe(1)
        ->and($credential->company_uuid)->toBe('company-1')
        ->and($credential->user_uuid)->toBe('user-1')
        ->and($credential->name)->toStartWith('NavigatorPairing-')
        ->and($credential->expires_at)->not->toBeNull()
        ->and($credential->expires_at->greaterThan(now()->addDays(6)))->toBeTrue()
        ->and($credential->expires_at->lessThanOrEqualTo(now()->addDays(7)))->toBeTrue();

    // New pairing must get a different, independently revocable credential.
    $second = $probe->callProtected('firstOrCreateNavigatorCredential', [$admin]);
    expect($connection->table('api_credentials')->count())->toBe(2)
        ->and($second->name)->toStartWith('NavigatorPairing-')
        ->and($second->name)->not->toBe($credential->name)
        ->and($second->expires_at)->not->toBeNull();
});

/**
 * S85 RED security gate: the legitimate issuer is company B while company A
 * has been inserted first. A link may never select a global first admin.
 *
 * Regression coverage for the explicit company lookup introduced in S87.
 */
test('navigator issuer lookup never selects the first admin from another tenant', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert([
        ['uuid' => 'company-a', 'name' => 'First Tenant'],
        ['uuid' => 'company-b', 'name' => 'Issuer Tenant'],
    ]);
    $connection->table('users')->insert([
        ['uuid' => 'user-a', 'company_uuid' => 'company-a', 'type' => 'admin'],
        ['uuid' => 'user-b', 'company_uuid' => 'company-b', 'type' => 'admin'],
    ]);

    // The issuer's company must be derived from the authenticated, signed
    // issuance record; this test supplies that required tenant explicitly.
    session(['company' => 'company-b']);
    $probe = new FleetOpsInternalNavigatorHelpersProbe();
    $admin = $probe->callProtected('findAdminUser', ['company-b']);

    expect($admin)->toBeInstanceOf(User::class)
        ->and($admin->company_uuid)->toBe('company-b')
        ->and($admin->uuid)->toBe('user-b');
});

/**
 * S85 RED security gate: the absence of an issuer in the selected tenant
 * must fail closed, even if another tenant has an administrator.
 */
test('navigator issuer lookup fails closed without a tenant admin', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert([
        ['uuid' => 'company-a', 'name' => 'Other Tenant'],
        ['uuid' => 'company-b', 'name' => 'No Admin Tenant'],
    ]);
    $connection->table('users')->insert([
        ['uuid' => 'user-a', 'company_uuid' => 'company-a', 'type' => 'admin'],
    ]);

    session(['company' => 'company-b']);
    $probe = new FleetOpsInternalNavigatorHelpersProbe();
    expect($probe->callProtected('findAdminUser', ['company-b']))->toBeNull();
});

function fleetopsNavigatorLinkTokenTable(SQLiteConnection $connection): void
{
    $connection->getSchemaBuilder()->create('fleetops_navigator_link_tokens', function ($table) {
        $table->bigIncrements('id');
        $table->string('token_hash', 64)->unique();
        $table->string('company_uuid', 36);
        $table->string('issuer_user_uuid', 36);
        $table->dateTime('expires_at');
        $table->dateTime('consumed_at')->nullable();
        $table->timestamps();
    });
}

test('navigator link issuance denies non-admin and mismatched company without database writes', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    fleetopsNavigatorLinkTokenTable($connection);
    $connection->table('companies')->insert([
        ['uuid' => 'company-a', 'name' => 'Company A'],
        ['uuid' => 'company-b', 'name' => 'Company B'],
    ]);
    $connection->table('users')->insert([
        ['uuid' => 'user-a', 'company_uuid' => 'company-a', 'type' => 'driver'],
        ['uuid' => 'user-b', 'company_uuid' => 'company-b', 'type' => 'admin'],
    ]);

    $service = new \Fleetbase\FleetOps\Support\NavigatorLinkTokenService();

    try {
        $service->issue(User::where('uuid', 'user-a')->firstOrFail());
        throw new \LogicException('Non-admin must be rejected');
    } catch (\RuntimeException $exception) {
        expect($connection->table('fleetops_navigator_link_tokens')->count())->toBe(0);
    }

    $issuer = User::where('uuid', 'user-b')->firstOrFail();
    $issuer->setRelation('company', Company::where('uuid', 'company-a')->firstOrFail());

    try {
        $service->issue($issuer);
        throw new \LogicException('Mismatched issuer company must be rejected');
    } catch (\RuntimeException $exception) {
        expect($connection->table('fleetops_navigator_link_tokens')->count())->toBe(0);
    }
});

test('navigator one-time link binds issuer to company B and rejects replay', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    fleetopsNavigatorLinkTokenTable($connection);
    $connection->table('companies')->insert([
        ['uuid' => 'company-a', 'name' => 'First Company'],
        ['uuid' => 'company-b', 'name' => 'Issuer Company'],
    ]);
    $connection->table('users')->insert([
        ['uuid' => 'user-a', 'company_uuid' => 'company-a', 'type' => 'admin'],
        ['uuid' => 'user-b', 'company_uuid' => 'company-b', 'type' => 'admin'],
    ]);

    $service = new \Fleetbase\FleetOps\Support\NavigatorLinkTokenService();
    $issuer = User::where('uuid', 'user-b')->firstOrFail();
    $token = $service->issue($issuer);

    expect(strlen($token))->toBe(64)
        ->and($connection->table('fleetops_navigator_link_tokens')->count())->toBe(1)
        ->and($connection->table('fleetops_navigator_link_tokens')->value('token_hash'))->toBe(hash('sha256', $token))
        ->and($connection->table('fleetops_navigator_link_tokens')->value('company_uuid'))->toBe('company-b')
        ->and($connection->table('fleetops_navigator_link_tokens')->value('issuer_user_uuid'))->toBe('user-b');

    $redeemed = $service->redeem($token);
    expect($redeemed)->toBeInstanceOf(User::class)
        ->and($redeemed->uuid)->toBe('user-b')
        ->and($redeemed->company_uuid)->toBe('company-b')
        ->and($service->redeem($token))->toBeNull()
        ->and($connection->table('fleetops_navigator_link_tokens')->whereNotNull('consumed_at')->count())->toBe(1);
});

test('navigator one-time link rejects tampered expired and disabled issuer tokens', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    fleetopsNavigatorLinkTokenTable($connection);
    $connection->table('companies')->insert(['uuid' => 'company-b', 'name' => 'Issuer']);
    $connection->table('users')->insert(['uuid' => 'user-b', 'company_uuid' => 'company-b', 'type' => 'admin']);

    $service = new \Fleetbase\FleetOps\Support\NavigatorLinkTokenService();
    $token = $service->issue(User::where('uuid', 'user-b')->firstOrFail());

    expect($service->redeem(str_repeat('0', 64)))->toBeNull()
        ->and($service->redeem(strtoupper($token)))->toBeNull()
        ->and($service->redeem('short'))->toBeNull();

    $connection->table('users')->where('uuid', 'user-b')->update(['type' => 'driver']);
    expect($service->redeem($token))->toBeNull()
        ->and($connection->table('fleetops_navigator_link_tokens')->whereNull('consumed_at')->count())->toBe(1);

    $connection->table('users')->where('uuid', 'user-b')->update(['type' => 'admin']);
    $connection->table('fleetops_navigator_link_tokens')->update(['expires_at' => now()->subMinute()->toDateTimeString()]);
    expect($service->redeem($token))->toBeNull()
        ->and($connection->table('fleetops_navigator_link_tokens')->whereNull('consumed_at')->count())->toBe(1);
});

test('deep link configuration helpers read url config and environment values', function () {
    fleetopsInternalNavigatorHelpersBoot();
    FleetOpsNavigatorHelpersEnv::$values = [
        'SOCKETCLUSTER_HOST'   => 'socket.fleetbase.test',
        'SOCKETCLUSTER_PORT'   => 38000,
        'SOCKETCLUSTER_SECURE' => 'true',
    ];

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    expect($probe->callProtected('secureRootUrl'))->toBe('https://fleetbase.test/')
        ->and($probe->callProtected('navigatorAppIdentifier'))->toBe('io.fleetbase.navigator')
        ->and($probe->callProtected('socketClusterHost'))->toBe('socket.fleetbase.test')
        ->and($probe->callProtected('socketClusterPort'))->toBe(38000)
        ->and($probe->callProtected('socketClusterSecure'))->toBeTrue();

    FleetOpsNavigatorHelpersEnv::$values = [];
    expect($probe->callProtected('socketClusterHost'))->toBe('socket')
        ->and($probe->callProtected('socketClusterPort'))->toBe(8000)
        ->and($probe->callProtected('socketClusterSecure'))->toBeFalse();
});

test('redirect away helper delegates to the redirector', function () {
    fleetopsInternalNavigatorHelpersBoot();
    $redirect = new FleetOpsNavigatorHelpersRedirectFake();
    app()->instance('redirect', $redirect);
    Illuminate\Support\Facades\Redirect::clearResolvedInstance('redirect');

    $result = (new FleetOpsInternalNavigatorHelpersProbe())->callProtected('redirectAway', ['flbnavigator://configure?key=abc']);

    expect($result)->toBe('redirected:flbnavigator://configure?key=abc')
        ->and($redirect->urls)->toBe(['flbnavigator://configure?key=abc']);
});

test('api credential token resolution finds keys and secrets', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('api_credentials')->insert([
        'uuid'         => 'cred-1',
        'company_uuid' => 'company-1',
        'key'          => 'flb_live_abc',
        'secret'       => '$secret_abc',
    ]);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $byKey = $probe->callProtected('findApiCredentialForToken', ['flb_live_abc', 'mysql', false]);
    expect($byKey)->toBeInstanceOf(ApiCredential::class)
        ->and($byKey->uuid)->toBe('cred-1');

    $bySecret = $probe->callProtected('findApiCredentialForToken', ['$secret_abc', 'mysql', true]);
    expect($bySecret)->toBeInstanceOf(ApiCredential::class);

    expect($probe->callProtected('findApiCredentialForToken', ['missing', 'mysql', false]))->toBeNull();
});

test('organization and driver onboard settings helpers execute against the database', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('settings')->insert(['key' => 'fleet-ops.driver-onboard', 'value' => json_encode(['enabled' => true])]);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $organization = $probe->callProtected('findOrganization', ['company-1']);
    expect($organization)->toBeInstanceOf(Company::class)
        ->and($organization->name)->toBe('Acme');

    $settings = $probe->callProtected('driverOnboardSettings');
    expect($settings)->not->toBeNull();
});
