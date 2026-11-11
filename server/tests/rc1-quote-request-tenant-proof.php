<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/proof/ServiceQuote.php';
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
]]);
DB::purge('sqlite');
Schema::connection('sqlite')->create('service_quotes', function ($table) {
    $table->string('uuid')->primary();
    $table->string('public_id');
    $table->string('company_uuid');
    $table->timestamp('expired_at')->nullable();
    $table->softDeletes();
});
DB::connection('sqlite')->table('service_quotes')->insert([
    ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'quote_AAAAAAAA', 'company_uuid' => 'company-a'],
    ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'quote_BBBBBBBB', 'company_uuid' => 'company-b'],
]);
function check(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, $name . '=FAIL' . PHP_EOL); exit(2); }
    echo $name . '=PASS' . PHP_EOL;
}
function resolveQuote(string $id): ?ServiceQuote {
    $r = new class($id) extends Request {
        public function __construct(private string $quote) { parent::__construct(); }
        public function or(array $keys, mixed $default = null): mixed { return $this->quote; }
    };
    return ServiceQuote::resolveFromRequest($r);
}
check(ServiceQuote::hasReferenceInRequest(new class extends Request {
    public function or(array $keys, mixed $default = null): mixed { return null; }
}) === false, 'OPTIONAL_NO_QUOTE_ACCEPTED');
check(ServiceQuote::hasReferenceInRequest(new class extends Request {
    public function or(array $keys, mixed $default = null): mixed { return 'quote_BBBBBBBB'; }
}) === true, 'EXPLICIT_QUOTE_DETECTED');
session(['company' => 'company-a']);
check(resolveQuote('11111111-1111-4111-8111-111111111111')?->company_uuid === 'company-a', 'UUID_OWN_QUOTE');
check(resolveQuote('22222222-2222-4222-8222-222222222222') === null, 'UUID_FOREIGN_DENIED');
check(resolveQuote('quote_AAAAAAAA')?->company_uuid === 'company-a', 'PUBLIC_ID_OWN_QUOTE');
check(resolveQuote('quote_BBBBBBBB') === null, 'PUBLIC_ID_FOREIGN_DENIED');
session()->forget('company');
check(resolveQuote('11111111-1111-4111-8111-111111111111') === null, 'MISSING_COMPANY_DENIED');
echo 'RC1_QUOTE_REQUEST_TENANT_BOUNDARY=PASS' . PHP_EOL;
