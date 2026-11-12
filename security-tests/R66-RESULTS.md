# GST Fleetbase S98-R66 - Real Pest suite in isolated container

Source fork exact pinned base 7f5a82f2d4be87f52bd5729e39384867af21345a.
Local branch feature/s98-r62-navigator-v2-isolated (not committed/pushed).
Runtime image gst-rc1-api:8e01d0b-r50-lab.
Test tools Pest 3.8.7 + PHPUnit 11.5.56 pinned by PR#11 lock.

## Harness
- Fresh ephemeral Docker --rm, --network none, --read-only,
  --memory 700m, --cpus 1, --pids-limit 100; no QA/prod access.
- Fork and Pest tools mounted readonly.
- Synthetic PHP bootstrap loads framework vendor from cached API image,
  overlays isolated FleetOps classes, and binds fake response factory.
- Reflection proves NavigatorController and NavigatorLinkTokenService
  come from the isolated fork, not API image vendor.
- --do-not-cache-result avoids attempts to write into read-only image.

## Results
Actual edited server/tests/NavigatorControllerContractsTest.php:
Pest/PHPUnit 8 tests, 31 assertions, zero errors/failures (PASS).
PHP 8.2.35, approx 40.50 MiB, 0.053s.
Source static 20/20 PASS, controller with doubles 14/14 PASS,
git diff --check PASS.

## Remaining limits
The Pest cases use fake issuer, token-service and credential models.
No real Laravel HTTP kernel, route authorization/middleware,
real MySQL NavigatorLinkTokenService, migrations, parallel redeem,
or verified mobile device identity were tested.
Synthetic response factory is not full app runtime.
No candidate QA deployment; Navigator V2 feature remains OFF.

## Decision
NO-GO. Next S98-R67: actual isolated MySQL integration and framework
HTTP tests with pinned code, then mobile cryptographic device proof.
