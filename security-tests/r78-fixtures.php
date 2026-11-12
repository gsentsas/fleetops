<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('DB_DATABASE') !== 'gst_rc1_qa' || !str_starts_with(getenv('DB_HOST') ?: '', 'gst-r78-')) {
    throw new RuntimeException('Disposable database required');
}
foreach (['users', 'companies', 'drivers', 'api_credentials', 'personal_access_tokens'] as $table) {
    if (\Illuminate\Support\Facades\DB::table($table)->count() !== 0) {
        throw new RuntimeException('Identity-empty archive required');
    }
}
$fixtures = [];
foreach (['adminA', 'adminB', 'staffA'] as $label) {
    $company = $label === 'staffA' ? $fixtures['adminA']['company'] : (string) \Illuminate\Support\Str::uuid();
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $password = bin2hex(random_bytes(24));
    $email = strtolower($label) . '@example.test';
    if ($label !== 'staffA') {
        \Illuminate\Support\Facades\DB::table('companies')->insert(['uuid' => $company, 'public_id' => 'company_' . bin2hex(random_bytes(8)), 'name' => 'Synthetic R78']);
    }
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'uuid' => $uuid, 'company_uuid' => $company, 'type' => $label === 'staffA' ? 'user' : 'admin',
        'status' => 'active', 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($label !== 'staffA') {
        \Illuminate\Support\Facades\DB::table('companies')->where('uuid', $company)->update(['owner_uuid' => $uuid]);
    }
    $fixtures[$label] = compact('email', 'password', 'uuid', 'company');
}
file_put_contents('/tmp/r78-fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
chmod('/tmp/r78-fixtures.json', 0600);
echo "R78_SYNTHETIC_FIXTURES=PASS\n";
