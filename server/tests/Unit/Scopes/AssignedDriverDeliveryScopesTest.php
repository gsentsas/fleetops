<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Scopes\AssignedDriverFileScope;
use Fleetbase\FleetOps\Scopes\AssignedDriverOrderScope;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class R235ScopeActor
{
    public static ?object $current = null;

    public static function getUserFromSession(?Request $request = null): ?object
    {
        return self::$current;
    }
}

if (!class_exists('Fleetbase\\Support\\Auth', false)) {
    class_alias(R235ScopeActor::class, 'Fleetbase\\Support\\Auth');
}

class R235ScopeFile extends Model
{
    protected $table = 'files';

    public $timestamps = false;
}

class R235ScopeOrder extends Model
{
    protected $table = 'orders';

    public $timestamps = false;
}

function r235ScopeDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['mysql' => $connection, 'default' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();

    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('driver_assigned_uuid')->nullable();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
    });
    $schema->create('drivers', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->softDeletes();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('uploader_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
    });
    $schema->create('proofs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('order_uuid');
        $table->string('file_uuid');
        $table->softDeletes();
    });
    $connection->table('users')->insert([
        ['uuid' => 'user-a'], ['uuid' => 'user-b'], ['uuid' => 'user-c'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-a', 'company_uuid' => 'tenant-a', 'user_uuid' => 'user-a'],
        ['uuid' => 'driver-b', 'company_uuid' => 'tenant-a', 'user_uuid' => 'user-b'],
        ['uuid' => 'driver-c', 'company_uuid' => 'tenant-b', 'user_uuid' => 'user-c'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-a', 'company_uuid' => 'tenant-a', 'driver_assigned_uuid' => 'driver-a'],
        ['uuid' => 'order-b', 'company_uuid' => 'tenant-a', 'driver_assigned_uuid' => 'driver-b'],
        ['uuid' => 'order-c', 'company_uuid' => 'tenant-b', 'driver_assigned_uuid' => 'driver-c'],
    ]);
    foreach ([
        ['uuid' => 'proof-a', 'company_uuid' => 'tenant-a'],
        ['uuid' => 'proof-b', 'company_uuid' => 'tenant-a'],
        ['uuid' => 'proof-c', 'company_uuid' => 'tenant-b'],
        ['uuid' => 'attachment-a', 'company_uuid' => 'tenant-a', 'subject_uuid' => 'order-a', 'subject_type' => Order::class],
        ['uuid' => 'attachment-b', 'company_uuid' => 'tenant-a', 'subject_uuid' => 'order-b', 'subject_type' => Order::class],
        ['uuid' => 'unlinked-a', 'company_uuid' => 'tenant-a', 'uploader_uuid' => 'user-a'],
        ['uuid' => 'unlinked-b', 'company_uuid' => 'tenant-a', 'uploader_uuid' => 'user-b'],
        ['uuid' => 'linked-reassigned', 'company_uuid' => 'tenant-a', 'uploader_uuid' => 'user-a'],
        ['uuid' => 'foreign-upload', 'company_uuid' => 'tenant-b', 'uploader_uuid' => 'user-a'],
    ] as $file) {
        $connection->table('files')->insert(array_merge([
            'uploader_uuid' => null, 'subject_uuid' => null, 'subject_type' => null,
        ], $file));
    }
    $connection->table('proofs')->insert([
        ['uuid' => 'p-a', 'company_uuid' => 'tenant-a', 'order_uuid' => 'order-a', 'file_uuid' => 'proof-a'],
        ['uuid' => 'p-b', 'company_uuid' => 'tenant-a', 'order_uuid' => 'order-b', 'file_uuid' => 'proof-b'],
        ['uuid' => 'p-c', 'company_uuid' => 'tenant-b', 'order_uuid' => 'order-c', 'file_uuid' => 'proof-c'],
        ['uuid' => 'p-reassigned', 'company_uuid' => 'tenant-a', 'order_uuid' => 'order-b', 'file_uuid' => 'linked-reassigned'],
    ]);

    session(['company' => 'tenant-a']);
    R235ScopeActor::$current = (object) ['uuid' => 'user-a', 'type' => 'driver'];

    return $connection;
}

function r235ScopeRoute(string $controller): void
{
    $request = Request::create('/api/v1/files', 'GET');
    $handler = $controller . '@findRecord';
    $route   = new Route('GET', '/api/v1/files', ['uses' => $handler, 'controller' => $handler]);
    $route->bind($request);
    $request->setRouteResolver(static fn () => $route);
    app()->instance('request', $request);
}

function r235VisibleFileUuids(): array
{
    $model = new R235ScopeFile();
    $query = $model->newQuery();
    (new AssignedDriverFileScope())->apply($query, $model);

    return $query->orderBy('files.uuid')->pluck('uuid')->all();
}

function r235VisibleOrderUuids(): array
{
    $model = new R235ScopeOrder();
    $query = $model->newQuery();
    (new AssignedDriverOrderScope())->apply($query, $model);

    return $query->orderBy('orders.uuid')->pluck('uuid')->all();
}

test('file scope restricts drivers to proofs, order attachments and safe unlinked uploads', function () {
    r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\Http\Controllers\Api\v1\FileController::class);

    expect(r235VisibleFileUuids())->toBe(['attachment-a', 'proof-a', 'unlinked-a']);
});

test('file scope revokes proof and attachment access after order reassignment', function () {
    $db = r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\Http\Controllers\Internal\v1\FileController::class);

    $db->table('orders')->where('uuid', 'order-a')->update(['driver_assigned_uuid' => 'driver-b']);

    expect(r235VisibleFileUuids())->toBe(['unlinked-a']);
});

test('file scope excludes soft deleted proofs, orders and drivers', function () {
    $db = r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\Http\Controllers\Api\v1\FileController::class);

    $db->table('proofs')->where('uuid', 'p-a')->update(['deleted_at' => now()]);
    expect(r235VisibleFileUuids())->toBe(['attachment-a', 'unlinked-a']);

    $db->table('orders')->where('uuid', 'order-a')->update(['deleted_at' => now()]);
    expect(r235VisibleFileUuids())->toBe(['unlinked-a']);

    $db->table('drivers')->where('uuid', 'driver-a')->update(['deleted_at' => now()]);
    expect(r235VisibleFileUuids())->toBe(['unlinked-a']);
});

test('file scope is a no-op for non-file controllers and non-driver principals', function () {
    r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class);
    expect(r235VisibleFileUuids())->toHaveCount(9);

    r235ScopeRoute(Fleetbase\Http\Controllers\Api\v1\FileController::class);
    R235ScopeActor::$current = (object) ['uuid' => 'user-a', 'type' => 'user'];
    expect(r235VisibleFileUuids())->toHaveCount(9);
});

test('order scope limits driver order lookups across companies and reassignment', function () {
    $db = r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class);
    expect(r235VisibleOrderUuids())->toBe(['order-a']);

    $db->table('orders')->where('uuid', 'order-a')->update(['driver_assigned_uuid' => 'driver-b']);
    expect(r235VisibleOrderUuids())->toBe([]);
});

test('order scope skips permission narrowing outside driver HTTP requests', function () {
    r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class);
    R235ScopeActor::$current = (object) ['uuid' => 'user-a', 'type' => 'user'];
    expect(r235VisibleOrderUuids())->toHaveCount(3);

    app()->forgetInstance('request');
    expect(r235VisibleOrderUuids())->toHaveCount(3);
});

test('file scope does not narrow non-HTTP queries or requests without drivers', function () {
    r235ScopeDatabase();
    app()->forgetInstance('request');
    expect(r235VisibleFileUuids())->toHaveCount(9);

    r235ScopeRoute(Fleetbase\Http\Controllers\Api\v1\FileController::class);
    R235ScopeActor::$current = null;
    expect(r235VisibleFileUuids())->toHaveCount(9);
});

test('order scope fails closed when a driver has no active company context', function () {
    r235ScopeDatabase();
    r235ScopeRoute(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class);
    session(['company' => null]);

    expect(r235VisibleOrderUuids())->toBe([]);
});
