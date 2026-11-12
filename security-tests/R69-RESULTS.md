# GST Fleetbase S98-R69 - Actual User and Company model compatibility

Isolated local fork base SHA 7f5a82f2d4be87f52bd5729e39384867af21345a.
Branch feature/s98-r62-navigator-v2-isolated, uncommitted.

## Test
Used actual Fleetbase Core API PHP User and Company classes loaded from
the pinned cached API image vendor, plus actual fork NavigatorLinkTokenService
and FleetOps navigator token migration. A disposable MySQL 8.0 was attached
only to a private Docker internal network, no published host port.
The User/Company SQL tables remained intentionally minimal and synthetic,
with deleted_at and timestamps needed by actual model global scopes.
8/8 dynamic gates passed: migration up; model provenance for both real
classes; actual Eloquent tenant association; issue; exact company B
redemption; replay rejection; migration down.

Earlier attempts failed due missing Capsule default, Laravel container
config binding and soft-delete column, then isolated harness corrected.
No production source code was changed to make the test pass.

## Mobile assessment
PR#11 has a prepared patch that REMOVES debug logging from
InstanceLinkHandler.tsx and legacy/App.js but does NOT implement
new POST redeem nor cryptographic device identity/approval.
Current get-link-app and deep-link client must be updated together.
Actual Navigator source/build and real Android integration NOT tested.
Do not activate the pairing_v2_enabled flag.

## Remaining blockers
Complete production Core API schema/migration replay, full Laravel HTTP
middleware authorization, concurrency with full models, mobile cryptographic
device proof, actual Android build, WSS/FCM and Plesk error-log privacy.
Focused 8/8 test does NOT mean full app integration is complete.

## Cleanup and release gate
Disposable MySQL/network removed. QA 4/4 healthy, production 8 running.
No commit, push, merge or deploy. NO-GO.
Next S98-R70: review actual Navigator mobile repository availability and
protocol/attestation choices, plus full-schema isolated acceptance.
