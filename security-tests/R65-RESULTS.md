# S98-R65 — Isolated Pest/PHPUnit and disposable MySQL

## Exact test runner
Retrieved tools/pest/composer.json and composer.lock from Fleet PR #11
HEAD 8e01d0b5cffa9c91fa4e3ee2e6dde36094129217.
Installed to isolated fork security-tests/vendor with composer install
--no-scripts --no-plugins, no changes to production/vendor.
Pest 3.8.7 and PHPUnit 11.5.56 now available locally.

## Real Pest attempt
Attempted server/tests/NavigatorControllerContractsTest.php.
Test did not bootstrap because Laravel/Fleetbase application autoload
was absent; missing Illuminate\Database\Eloquent\Model and
Fleetbase\FleetOps\Support\NavigatorLinkTokenService. This is NOT an
execution failure of the underlying business cases. No Pest cases ran.

## Disposable MySQL
Started mysql:8.0-oracle isolated container gst-r65-mysql, bounded
memory/CPU and loopback-only TCP port 127.0.0.1:13365; schema
synthetic_r65 using no real credentials or customer data. PHP PDO
executed 7/7 synthetic SQL transaction checks: table/schema,
tenant check, first redeem, replay from second connection, forgery,
expiry, and rollback without consumption.
Crucially this used a **synthetic SQL implementation**, not the
actual Laravel NavigatorLinkTokenService and not full concurrency
race between simultaneous transactions. Do not treat as integration
approval. No QA MySQL service was accessed.

The container was stopped with --rm and confirmed absent.

## Other gates
R64 controller-with-doubles runtime = 14/14 PASS.
R63 source static controls = 20/20 PASS.
Feature V2 still OFF by default. API and Android not synchronized.
No device possession proof, Plesk error log mitigation or mobile
acceptance. No commit/push/merge/QA deploy/prod deploy.

## Next
Build isolated actual Laravel API harness with correct Composer lock and
autoload (without modifying running QA image). Execute real Pest tests,
complete MySQL multitenant and concurrency checks and safe replay/rollback.
Do not activate V2 before device-authenticated mobile protocol and real
HTTP security tests pass.
