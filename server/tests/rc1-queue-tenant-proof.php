<?php
/**
 * Exact-image RC1 queue tenant proof. Run with isolated SQLite memory and
 * read-only patched FleetOps classes, never against QA or production MySQL.
 */
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/proof/FinalizeApiOrderCreation.php';
require '/proof/FinalizeInternalOrderCreation.php';

use Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation as ApiJob;
use Fleetbase\FleetOps\Jobs\FinalizeInternalOrderCreation as InternalJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    'foreign_key_constraints' => true,
]]);
DB::purge('sqlite');
Schema::connection('sqlite')->create('orders', function ($table) {
    $table->string('uuid')->primary();
    $table->string('company_uuid');
    $table->softDeletes();
});
Schema::connection('sqlite')->create('service_quotes', function ($table) {
    $table->string('uuid')->primary();
    $table->string('company_uuid');
    $table->timestamp('expired_at')->nullable();
    $table->softDeletes();
});
DB::connection('sqlite')->table('orders')->insert([
    ['uuid' => 'order-a', 'company_uuid' => 'company-a'],
    ['uuid' => 'order-b', 'company_uuid' => 'company-b'],
]);
DB::connection('sqlite')->table('service_quotes')->insert([
    ['uuid' => 'quote-a', 'company_uuid' => 'company-a'],
    ['uuid' => 'quote-b', 'company_uuid' => 'company-b'],
]);
function check(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, $name . '=FAIL' . PHP_EOL); exit(2); }
    echo $name . '=PASS' . PHP_EOL;
}
foreach ([new ApiJob('order-a'), new InternalJob('order-a')] as $old) {
    $denied = false;
    try { $old->handle(); } catch (RuntimeException $e) {
        $denied = $e->getMessage() === 'Queued order finalization is missing its tenant.';
    }
    check($denied, 'LEGACY_WITHOUT_TENANT_FAIL_CLOSED');
}
$api = new ApiJob('order-a', 'quote-b', false, 'company-a');
$internal = new InternalJob('order-a', 'company-a');
$lookup = new ReflectionMethod($api, 'findOrder');
check($lookup->invoke($api)?->uuid === 'order-a', 'API_OWN_ORDER');
$other = new ApiJob('order-b', null, false, 'company-a');
check($lookup->invoke($other) === null, 'API_CROSS_TENANT_ORDER_DENIED');
$lookupQuote = new ReflectionMethod($api, 'findServiceQuote');
check($lookupQuote->invoke($api) === null, 'CROSS_TENANT_QUOTE_DENIED');
$rejected = false;
try { $api->handle(); } catch (RuntimeException $e) {
    $rejected = $e->getMessage() === 'Queued service quote was not found for this tenant.';
}
check($rejected, 'FOREIGN_QUOTE_JOB_FAILS_CLOSED_BEFORE_SIDE_EFFECTS');
$ownQuote = new ApiJob('order-a', 'quote-a', false, 'company-a');
check($lookupQuote->invoke($ownQuote)?->uuid === 'quote-a', 'OWN_QUOTE_ALLOWED');
$internalLookup = new ReflectionMethod($internal, 'findOrder');
check($internalLookup->invoke($internal)?->uuid === 'order-a', 'INTERNAL_OWN_ORDER');
$foreignInternal = new InternalJob('order-b', 'company-a');
check($internalLookup->invoke($foreignInternal) === null, 'INTERNAL_CROSS_TENANT_ORDER_DENIED');
echo 'RC1_QUEUE_TENANT_BOUNDARY=PASS' . PHP_EOL;
