<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\DeliveryProofAuthorization;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

class ProofAuthorizationUserFixture extends User implements Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;
}

const PROOF_COMPANY = '10000000-0000-4000-8000-000000000001';
const PROOF_USER    = '20000000-0000-4000-8000-000000000001';
const PROOF_MEMBER  = '30000000-0000-4000-8000-000000000001';

beforeEach(function () {
    $runtime = realpath(getcwd() . '/server_vendor') ?: realpath(getcwd() . '/vendor');
    config([
        'auth.defaults.guard'     => 'sanctum',
        'auth.guards.sanctum'     => ['driver' => 'sanctum', 'provider' => 'users'],
        'auth.guards.web'         => ['driver' => 'session', 'provider' => 'users'],
        'auth.providers.users'    => ['driver' => 'eloquent', 'model' => ProofAuthorizationUserFixture::class],
        'permission'              => require $runtime . '/fleetbase/core-api/config/permission.php',
        'cache.default'           => 'array',
        'cache.stores.array'      => ['driver' => 'array'],
        'fleetbase.connection.db' => 'mysql',
        'sanctum.expiration'      => null,
    ]);
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => 'mysql']);
    $resolver   = new ConnectionResolver(['mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    foreach (['users', 'company_users', 'api_credentials', 'drivers'] as $table) {
        $schema->create($table, function ($table) {
            $table->string('uuid')->primary();
            foreach (['company_uuid', 'user_uuid', 'status', 'type', 'password', '_key', 'name', 'key', 'secret'] as $name) {
                $table->string($name)->nullable();
            }
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    foreach (['permissions', 'roles', 'policies'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    foreach (['model_has_permissions' => 'permission_id', 'model_has_roles' => 'role_id', 'model_has_policies' => 'policy_id'] as $name => $foreign) {
        $schema->create($name, function ($table) use ($foreign) {
            $table->integer($foreign);
            $table->string('model_uuid');
            $table->string('model_type');
        });
    }
    foreach (['role_has_permissions' => ['role_id', 'permission_id'], 'policy_has_permissions' => ['policy_id', 'permission_id']] as $name => $columns) {
        $schema->create($name, function ($table) use ($columns) {
            foreach ($columns as $column) {
                $table->integer($column);
            }
        });
    }
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token');
        $table->text('abilities');
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->string('navigator_credential_uuid')->nullable();
        $table->timestamps();
    });
    $connection->table('users')->insert(['uuid' => PROOF_USER, 'company_uuid' => PROOF_COMPANY, 'status' => 'active', 'type' => 'user', 'password' => 'synthetic-hash']);
    $connection->table('company_users')->insert(['uuid' => PROOF_MEMBER, 'company_uuid' => PROOF_COMPANY, 'user_uuid' => PROOF_USER, 'status' => 'active']);
    $connection->table('permissions')->insert(['id' => 1, 'name' => 'fleet-ops update order', 'guard_name' => 'sanctum']);
    $connection->table('model_has_permissions')->insert(['permission_id' => 1, 'model_uuid' => PROOF_MEMBER, 'model_type' => CompanyUser::class]);
    app()->instance(CacheManager::class, new CacheManager(app()));
    app()->instance(PermissionRegistrar::class, new PermissionRegistrar(app(CacheManager::class)));
    session(['company' => PROOF_COMPANY, 'user' => PROOF_USER]);
    Sanctum::$accessTokenAuthenticationCallback = null;
    $this->connection                           = $connection;
});

function proofAuthorizationRequest(bool $cookie = false): array
{
    $user    = ProofAuthorizationUserFixture::findOrFail(PROOF_USER);
    $request = new Request();
    $request->setUserResolver(static fn () => $user);
    $order = new Order();
    $order->setRawAttributes(['uuid' => '40000000-0000-4000-8000-000000000001', 'company_uuid' => PROOF_COMPANY, 'driver_assigned_uuid' => null]);
    app()->instance('request', $request);
    $token = null;
    if (!$cookie) {
        $token = $user->createToken('R235 synthetic test', ['*'], now()->addHour());
        $request->headers->set('Authorization', 'Bearer ' . $token->plainTextToken);
    } else {
        $store = new Store('r235-test', new ArraySessionHandler(120));
        $store->start();
        $guard = new SessionGuard('web', new EloquentUserProvider(new class implements Illuminate\Contracts\Hashing\Hasher {
            public function info($value)
            {
                return password_get_info($value);
            }

            public function make($value, array $options = [])
            {
                return password_hash($value, PASSWORD_BCRYPT, ['cost' => 4]);
            }

            public function check($value, $hash, array $options = [])
            {
                return password_verify($value, $hash);
            }

            public function needsRehash($hash, array $options = [])
            {
                return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 4]);
            }
        }, ProofAuthorizationUserFixture::class), $store);
        $guard->setUser($user);
        $store->put($guard->getName(), PROOF_USER);
        $store->put('company', PROOF_COMPANY);
        $store->put('password_hash_web', 'synthetic-hash');
        $store->save();
        $store->start();
        $request->setLaravelSession($store);
        Fleetbase\Support\Auth::clearResolvedInstance('auth');
        app()->instance('auth', new class($guard) {
            public function __construct(private $guard)
            {
            }

            public function guard($name = null)
            {
                return $this->guard;
            }

            public function user()
            {
                return $this->guard->user();
            }
        });
    }

    return [$order, $request, $user, $token];
}

function proofAuthorizationStatus(Order $order, Request $request): int
{
    try {
        (new DeliveryProofAuthorization())->authorize($order, $request);

        return 200;
    } catch (HttpResponseException $exception) {
        return $exception->getResponse()->getStatusCode();
    }
}

test('proof authorization reloads a permitted native principal', function (bool $cookie) {
    [$order, $request, $original] = proofAuthorizationRequest($cookie);
    expect(proofAuthorizationStatus($order, $request))->toBe(200)
        ->and($request->user()->uuid)->toBe($original->uuid)
        ->and($request->user())->not->toBe($original);
})->with([false, true]);

test('proof authorization discards a primed wildcard index after permission revocation', function (bool $cookie) {
    [$order, $request, $user] = proofAuthorizationRequest($cookie);
    expect($user->hasPermissionTo('fleet-ops update order'))->toBeTrue();
    // A direct committed delete deliberately avoids in-process cache invalidation,
    // as a revocation in another worker cannot clear this worker's cached index.
    $this->connection->table('model_has_permissions')->delete();
    expect(proofAuthorizationStatus($order, $request))->toBe(403);
})->with([false, true]);

test('proof authorization rejects revoked or expired native tokens', function (string $change) {
    [$order, $request, $user, $issued] = proofAuthorizationRequest();
    $query                             = $this->connection->table('personal_access_tokens')->where('id', $issued->accessToken->id);
    if ($change === 'revoked') {
        $query->delete();
    } else {
        $query->update(['expires_at' => now()->subMinute()]);
    }
    expect(proofAuthorizationStatus($order, $request))->toBe(401);
})->with(['revoked', 'expired']);

test('proof authorization enforces token age and native authentication callback', function (string $policy) {
    [$order, $request, $user, $issued] = proofAuthorizationRequest();
    if ($policy === 'age') {
        config(['sanctum.expiration' => 1]);
        $this->connection->table('personal_access_tokens')->where('id', $issued->accessToken->id)->update(['created_at' => now()->subMinutes(2)]);
    } else {
        Sanctum::$accessTokenAuthenticationCallback = static fn () => false;
    }
    expect(proofAuthorizationStatus($order, $request))->toBe(401);
})->with(['age', 'callback']);

test('proof authorization rejects inactive principals or memberships', function (string $target, int $status) {
    [$order, $request] = proofAuthorizationRequest();
    $this->connection->table($target)->update(['status' => 'inactive']);
    expect(proofAuthorizationStatus($order, $request))->toBe($status);
})->with([['users', 401], ['company_users', 403]]);

test('proof authorization invalidates a destroyed persisted session without resurrecting its login', function () {
    [$order, $request] = proofAuthorizationRequest(true);
    $store             = $request->session();
    $oldId             = $store->getId();
    $store->getHandler()->destroy($oldId);
    expect(proofAuthorizationStatus($order, $request))->toBe(401)
        ->and($store->getId())->not->toBe($oldId);
    $store->save();
    $readback = new Store('r235-test', $store->getHandler(), $oldId);
    $readback->start();
    expect($readback->has(Fleetbase\Support\Auth::guard('web')->getName()))->toBeFalse();
});

test('proof authorization rejects changed session passwords', function () {
    [$order, $request] = proofAuthorizationRequest(true);
    $this->connection->table('users')->update(['password' => 'changed-synthetic-hash']);
    expect(proofAuthorizationStatus($order, $request))->toBe(401);
});

test('proof authorization isolates the active company', function () {
    [$order, $request]   = proofAuthorizationRequest();
    $order->company_uuid = '10000000-0000-4000-8000-000000000002';
    expect(proofAuthorizationStatus($order, $request))->toBe(403);
});

test('proof authorization rechecks a driver assignment', function (bool $assigned) {
    $this->connection->table('users')->update(['type' => 'driver']);
    [$order, $request] = proofAuthorizationRequest();
    if ($assigned) {
        $driver = '50000000-0000-4000-8000-000000000001';
        $this->connection->table('drivers')->insert(['uuid' => $driver, 'company_uuid' => PROOF_COMPANY, 'user_uuid' => PROOF_USER, 'status' => 'active']);
        $order->driver_assigned_uuid = $driver;
    }
    expect(proofAuthorizationStatus($order, $request))->toBe($assigned ? 200 : 404);
})->with([false, true]);

test('proof authorization refuses an empty active company before any mutation', function () {
    [$order, $request] = proofAuthorizationRequest();
    session(['company' => '']);

    expect(proofAuthorizationStatus($order, $request))->toBe(401);
});

test('proof authorization rejects a company context change during native identity refresh', function () {
    [$order, $request] = proofAuthorizationRequest();
    $authorizer        = new class extends DeliveryProofAuthorization {
        protected function freshIdentity(Request $request): ?User
        {
            $fresh = parent::freshIdentity($request);
            session(['company' => 'tenant-switched-while-waiting']);

            return $fresh;
        }
    };

    try {
        $authorizer->authorize($order, $request);
        $status = 200;
    } catch (HttpResponseException $exception) {
        $status = $exception->getResponse()->getStatusCode();
    }
    expect($status)->toBe(403);
});

test('proof authorization rejects cookie requests with no started session store', function (bool $hasStore) {
    [$order, , $original] = proofAuthorizationRequest(true);
    $request              = new Request();
    $request->setUserResolver(static fn () => $original);
    if ($hasStore) {
        $unstarted = new Store('r235-test', new ArraySessionHandler(120));
        $request->setLaravelSession($unstarted);
    }
    app()->instance('request', $request);

    expect(proofAuthorizationStatus($order, $request))->toBe(401);
})->with([false, true]);

test('proof authorization rejects deleted users from a persisted browser session', function () {
    [$order, $request] = proofAuthorizationRequest(true);
    $this->connection->table('users')->where('uuid', PROOF_USER)->delete();

    expect(proofAuthorizationStatus($order, $request))->toBe(401);
});

test('proof authorization rejects a persisted browser session bound to a different company', function () {
    [$order, $request] = proofAuthorizationRequest(true);
    $store             = $request->session();
    $store->put('company', 'another-tenant');
    $store->save();

    expect(proofAuthorizationStatus($order, $request))->toBe(401);
});

function r235ProofAuthorizationWith(DeliveryProofAuthorization $auth, Order $order, Request $request): int
{
    try {
        $auth->authorize($order, $request);

        return 200;
    } catch (HttpResponseException $exception) {
        return $exception->getResponse()->getStatusCode();
    }
}

test('proof reauthorization does not trust a successful auth signal without a User principal', function () {
    [$order, $request] = proofAuthorizationRequest();

    $authorization = new class extends DeliveryProofAuthorization {
        protected function authenticateOnceWithBasic(Request $probe): mixed
        {
            // Reproduce an auth middleware reporting success without a principal.
            $probe->setUserResolver(static fn () => null);

            return true;
        }
    };

    expect(r235ProofAuthorizationWith($authorization, $order, $request))->toBe(401);
});

test('proof reauthorization rejects a token expired between the primary and secondary reads', function () {
    [$order, $request, , $issued] = proofAuthorizationRequest();
    $connection                   = $this->connection;
    $tokenId                      = (int) $issued->accessToken->id;

    $authorization = new class($connection, $tokenId) extends DeliveryProofAuthorization {
        public function __construct(private SQLiteConnection $connection, private int $tokenId)
        {
        }

        protected function authenticateOnceWithBasic(Request $probe): mixed
        {
            $user = ProofAuthorizationUserFixture::findOrFail(PROOF_USER);
            $probe->setUserResolver(static fn () => $user);
            // Simulate an expiry committed by a separate worker after Core auth
            // but immediately before the independent Sanctum read.
            $this->connection->table('personal_access_tokens')->where('id', $this->tokenId)
                ->update(['expires_at' => now()->subMinute()]);

            return true;
        }
    };

    expect(r235ProofAuthorizationWith($authorization, $order, $request))->toBe(401);
});

test('fresh browser session identity refuses a persisted tenant change without cached credentials', function () {
    [, $request] = proofAuthorizationRequest(true);
    $request->session()->put('company', 'foreign-tenant');
    $request->session()->save();

    $method = new ReflectionMethod(DeliveryProofAuthorization::class, 'freshSessionIdentity');
    $method->setAccessible(true);

    expect($method->invoke(new DeliveryProofAuthorization(), $request))->toBeNull();
});

test('persisted browser company mismatch is rejected by the fresh session identity check', function () {
    [, $request] = proofAuthorizationRequest(true);
    $method      = new ReflectionMethod(DeliveryProofAuthorization::class, 'freshSessionIdentity');
    $method->setAccessible(true);

    $before = $method->invoke(new DeliveryProofAuthorization(), $request);
    expect($before)->toBeInstanceOf(User::class);

    $request->session()->put('company', 'foreign-company-after-login');
    $request->session()->save();
    expect(session('company'))->toBe(PROOF_COMPANY);

    $after = $method->invoke(new DeliveryProofAuthorization(), $request);
    expect($after)->toBeNull();
});
