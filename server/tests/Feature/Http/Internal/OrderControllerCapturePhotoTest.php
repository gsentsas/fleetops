<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Models\env')) {
    eval('namespace Fleetbase\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\env')) {
    eval('namespace Fleetbase\FleetOps\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Models\asset')) {
    eval('namespace Fleetbase\Models; function asset($path = null, $secure = null) { return "https://assets.example.com/" . ltrim((string) $path, "/"); }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the internal OrderController capturePhoto endpoint against SQLite
 * with a real validation factory so the closure photo rules execute:
 * base64 photo captures persisting proofs and stored files, waypoint
 * subject resolution, invalid photo strings rejected with 422 errors, and
 * the missing order and empty photo branches.
 */
if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

function fleetopsCapturePhotoContainer(): void
{
    $current = Illuminate\Container\Container::getInstance();
    if (method_exists($current, 'environment')) {
        return;
    }

    // Resource lifecycle serialization calls app()->environment(), which the
    // harness container lacks — swap in a subclass carrying the same state
    $replacement = new class extends Illuminate\Container\Container {
        public function environment(...$environments)
        {
            if (empty($environments)) {
                return 'testing';
            }

            $checks = is_array($environments[0]) ? $environments[0] : $environments;

            return in_array('testing', $checks, true);
        }
    };

    foreach (['bindings', 'instances', 'aliases', 'abstractAliases', 'resolved', 'extenders', 'tags', 'contextual', 'scopedInstances', 'reboundCallbacks', 'globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
        if (!property_exists(Illuminate\Container\Container::class, $property)) {
            continue;
        }
        $reflection = new ReflectionProperty(Illuminate\Container\Container::class, $property);
        $reflection->setAccessible(true);
        if ($reflection->isInitialized($current)) {
            $reflection->setValue($replacement, $reflection->getValue($current));
        }
    }

    Illuminate\Container\Container::setInstance($replacement);
    Illuminate\Support\Facades\Facade::setFacadeApplication($replacement);
}

class FleetOpsCapturePhotoStorageProbe extends Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController
{
    protected function requireFreshProofAuthorizationSnapshot(): void
    {
        // This fixture tests persistence only. Production MySQL post-lock
        // authorization is tested separately with authenticated HTTP requests.
    }

    protected function authorizeLockedProofCapture(Fleetbase\FleetOps\Models\Order $order): void
    {
        // Authorization is covered by the native HTTP integration suite.
    }
}

function fleetopsCapturePhotoBoot(): SQLiteConnection
{
    fleetopsCapturePhotoContainer();
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    // Laravel 12 validation: use the real factory and translator so invalid
    // image inputs return ValidationException with actual messages.
    // The locked RC1 runtime ships Laravel's translator contract, but not
    // illuminate/translation. Use a minimal translator for this test only.
    $translator = new class implements Illuminate\Contracts\Translation\Translator {
        private string $locale = 'en';

        public function get($key, array $replace = [], $locale = null)
        {
            return strtr((string) $key, $replace);
        }

        public function choice($key, $number, array $replace = [], $locale = null)
        {
            return (string) $this->get($key, $replace, $locale);
        }

        public function getLocale()
        {
            return $this->locale;
        }

        public function setLocale($locale)
        {
            $this->locale = (string) $locale;
        }
    };
    app()->instance('validator', new Illuminate\Validation\Factory($translator, app()));
    Illuminate\Support\Facades\Validator::clearResolvedInstance('validator');
    app()->instance(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class, new FleetOpsCapturePhotoStorageProbe());

    $disk = new class implements Illuminate\Contracts\Filesystem\Filesystem {
        public array $writes = [];

        public function path($path)
        {
            return '/unit-test/' . ltrim((string) $path, '/');
        }

        public function putFile($path, $file = null, $options = [])
        {
            return $this->putFileAs($path, $file, basename((string) $path), $options);
        }

        public function putFileAs($path, $file, $name = null, $options = [])
        {
            $target         = rtrim((string) $path, '/') . '/' . ltrim((string) $name, '/');
            $this->writes[] = $target;

            return $target;
        }

        public function url($path)
        {
            return 'https://cdn.example.com/' . ltrim((string) $path, '/');
        }

        public function exists($path)
        {
            return true;
        }

        public function get($path)
        {
            return '';
        }

        public function readStream($path)
        {
            return null;
        }

        public function put($path, $contents, $options = [])
        {
            $this->writes[] = $path;

            return true;
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return true;
        }

        public function getVisibility($path)
        {
            return 'public';
        }

        public function setVisibility($path, $visibility)
        {
            return true;
        }

        public function prepend($path, $data)
        {
            return true;
        }

        public function append($path, $data)
        {
            return true;
        }

        public function delete($paths)
        {
            return true;
        }

        public function copy($from, $to)
        {
            return true;
        }

        public function move($from, $to)
        {
            return true;
        }

        public function size($path)
        {
            return 0;
        }

        public function lastModified($path)
        {
            return 0;
        }

        public function files($directory = null, $recursive = false)
        {
            return [];
        }

        public function allFiles($directory = null)
        {
            return [];
        }

        public function directories($directory = null, $recursive = false)
        {
            return [];
        }

        public function allDirectories($directory = null)
        {
            return [];
        }

        public function makeDirectory($path)
        {
            return true;
        }

        public function deleteDirectory($directory)
        {
            return true;
        }
    };
    $storage = new class($disk) {
        public function __construct(public $d)
        {
        }

        public function disk($diskName = null)
        {
            return $this->d;
        }

        public function __call($method, $arguments)
        {
            return $this->d->{$method}(...$arguments);
        }
    };
    app()->instance('filesystem', $storage);
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
    $GLOBALS['fleetopsCapturePhotoStorage'] = $disk;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'status', 'type', 'dispatched', 'started'],
        'payloads'         => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'meta', 'type'],
        'waypoints'        => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type'],
        'entities'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'proofs'           => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data', '_key'],
        'files'            => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'folder', 'meta', 'type', 'size', 'file_size', 'slug', 'subject_uuid', 'subject_type', '_key'],
        'companies'        => ['uuid', 'public_id', 'name', 'country'],
        'waypoint_markers' => ['uuid', 'company_uuid', 'payload_uuid', 'waypoint_uuid', 'tracking_number_uuid'],
        'tracking_numbers' => ['uuid', 'company_uuid', 'status_uuid', 'tracking_number'],
        'statuses'         => ['uuid', 'public_id', 'name', 'code'],
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

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.s3.bucket', 'test-bucket');

    session(['company' => 'company-1', 'user' => 'user-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsCapturePhotoValidPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

function fleetopsCapturePhotoRequest(array $input): Request
{
    $request = Request::create('/int/v1/orders/capture-photo', 'POST', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

function fleetopsCapturePhotoSeed(SQLiteConnection $connection): void
{
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_photostop', 'company_uuid' => 'company-1', 'name' => 'Stop']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_photoone', 'company_uuid' => 'company-1', 'current_waypoint_uuid' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('waypoints')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'waypoint_photoone', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'order' => 0]);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_photoone1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'status' => 'created']);
}

test('base64 photos persist proofs with stored files', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $photo  = 'data:image/png;base64,' . base64_encode(fleetopsCapturePhotoValidPng());
    $result = (new OrderController())->capturePhoto(fleetopsCapturePhotoRequest([
        'photos'  => [$photo],
        'remarks' => 'Photo proof',
        'data'    => ['angle' => 'front'],
    ]), 'order_photoone1', 'waypoint_photoone');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->count())->toBe(1)
        ->and($connection->table('files')->count())->toBe(1)
        ->and($connection->table('proofs')->value('subject_uuid'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and($GLOBALS['fleetopsCapturePhotoStorage']->writes)->toHaveCount(1);
});

test('photo capture refuses parent order when a multi-stop waypoint is active', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $result = (new OrderController())->capturePhoto(fleetopsCapturePhotoRequest([
        'photos' => [base64_encode(fleetopsCapturePhotoValidPng())],
    ]), 'order_photoone1');

    expect($result->getStatusCode())->toBe(409)
        ->and($result->getData(true)['error'])->toBe('Proof must target the active unfinished stop.')
        ->and($connection->table('proofs')->count())->toBe(0)
        ->and($connection->table('files')->count())->toBe(0);
});

test('waypoint subjects resolve for scoped photo captures', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $photo  = base64_encode(fleetopsCapturePhotoValidPng());
    $result = (new OrderController())->capturePhoto(fleetopsCapturePhotoRequest([
        'photos' => [$photo],
    ]), 'order_photoone1', 'waypoint_photoone');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->value('subject_uuid'))->toBe('22222222-2222-4222-8222-222222222222');
});

test('invalid photos missing orders and empty payloads error', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);
    $controller = new OrderController();

    // Invalid base64 strings fail the closure rule with a 422
    $invalid = $controller->capturePhoto(fleetopsCapturePhotoRequest(['photos' => ['!!not-base64!!']]), 'order_photoone1');
    expect($invalid->getStatusCode())->toBe(422);

    // No photos at all also fails validation
    $empty = $controller->capturePhoto(fleetopsCapturePhotoRequest([]), 'order_photoone1');
    expect($empty->getStatusCode())->toBe(422);

    // Unknown orders return the error response
    $missing = $controller->capturePhoto(fleetopsCapturePhotoRequest(['photos' => [base64_encode(fleetopsCapturePhotoValidPng())]]), 'order_missing99');
    expect($missing->getStatusCode())->toBeGreaterThanOrEqual(400);
});

test('public api capture photo validates and persists base64 proofs', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $apiController = new FleetOpsCapturePhotoStorageProbe();

    // Invalid base64 rejects with the first validation message
    $invalid = $apiController->capturePhoto(fleetopsCapturePhotoRequest(['photos' => ['!!not-base64!!']]), 'order_photoone1');
    expect($invalid->getStatusCode())->toBe(422);

    // Valid base64 photos persist proofs with stored files through the
    // public api surface
    $result = $apiController->capturePhoto(fleetopsCapturePhotoRequest([
        'photos'  => [base64_encode(fleetopsCapturePhotoValidPng())],
        'remarks' => 'Public capture',
    ]), 'order_photoone1', 'waypoint_photoone');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->count())->toBe(1)
        ->and($connection->table('proofs')->whereNotNull('file_uuid')->count())->toBe(1);
});

test('public api capture photo stores uploaded files as proofs', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $temp = tempnam(sys_get_temp_dir(), 'proof') . '.png';
    file_put_contents($temp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $upload = new Illuminate\Http\UploadedFile($temp, 'proof.png', 'image/png', null, true);

    $request = Request::create('/v1/orders/capture-photo', 'POST', [], [], ['photos' => [$upload]]);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    $result = (new FleetOpsCapturePhotoStorageProbe())->capturePhoto($request, 'order_photoone1', 'waypoint_photoone');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->count())->toBe(1)
        ->and($connection->table('files')->where('content_type', 'image/png')->count())->toBe(1);

    @unlink($temp);
});

test('internal capture photo accepts uploads and rejects unusable subjects', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $temp = tempnam(sys_get_temp_dir(), 'proof') . '.png';
    file_put_contents($temp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $upload = new Illuminate\Http\UploadedFile($temp, 'proof.png', 'image/png', null, true);

    $request = Request::create('/int/v1/orders/capture-photo', 'POST', [], [], ['photos' => [$upload]]);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    $result = (new OrderController())->capturePhoto($request, 'order_photoone1', 'waypoint_photoone');
    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('files')->where('content_type', 'image/png')->count())->toBe(1);

    // Uploads with a disallowed extension fail the closure rule
    $textFile = tempnam(sys_get_temp_dir(), 'proof') . '.txt';
    file_put_contents($textFile, 'not really an image');
    $badUpload  = new Illuminate\Http\UploadedFile($textFile, 'notes.txt', 'text/plain', null, true);
    $badRequest = Request::create('/int/v1/orders/capture-photo', 'POST', [], [], ['photos' => [$badUpload]]);
    $badRequest->setLaravelSession($store);

    $rejected = (new OrderController())->capturePhoto($badRequest, 'order_photoone1');
    expect($rejected->getStatusCode())->toBe(422);

    // Subject ids that resolve to nothing on the order are refused
    $unresolvable = (new OrderController())->capturePhoto(
        fleetopsCapturePhotoRequest(['photos' => [base64_encode(fleetopsCapturePhotoValidPng())]]),
        'order_photoone1',
        'waypoint_notonthisorder'
    );
    expect($unresolvable->getStatusCode())->toBe(422)
        ->and($unresolvable->getData(true)['error'] ?? '')->toContain('Unable to capture photo as proof');

    @unlink($temp);
    @unlink($textFile);
});

test('public api capture photo rejects bad uploads and non string photos', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);
    $apiController = new FleetOpsCapturePhotoStorageProbe();

    // Uploads that are not images fail the file arm of the closure
    $textFile = tempnam(sys_get_temp_dir(), 'proof') . '.txt';
    file_put_contents($textFile, 'plain text, not an image');
    $badUpload = new Illuminate\Http\UploadedFile($textFile, 'notes.txt', 'text/plain', null, true);
    $request   = Request::create('/v1/orders/capture-photo', 'POST', [], [], ['photos' => [$badUpload]]);
    $store     = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    $rejected = $apiController->capturePhoto($request, 'order_photoone1');
    expect($rejected->getStatusCode())->toBe(422)
        ->and($rejected->getData(true)['error'] ?? '')->toContain('valid image');

    // Values that are neither uploads nor strings fail the final arm
    $nonString = $apiController->capturePhoto(fleetopsCapturePhotoRequest(['photos' => [['nested' => 'array']]]), 'order_photoone1');
    expect($nonString->getStatusCode())->toBe(422)
        ->and($nonString->getData(true)['error'] ?? '')->toContain('image file or a Base64 string');

    @unlink($textFile);
});
