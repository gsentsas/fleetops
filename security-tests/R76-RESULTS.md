# GST Fleetbase S98-R76 - Core schema, Navigator scope and revocation gates

## Scope
Fork FleetOps lab /opt/gst-rc1-staging/s98-r62-fleetops-v2,
branch feature/s98-r62-navigator-v2-isolated, original SHA
7f5a82f2d4be87f52bd5729e39384867af21345a.
All source modifications uncommitted and unpushed.
No access/mutation of production or QA databases, no release.

## Actual Core API migrations on disposable MySQL 8
Used actual migration files from pinned gst-rc1-api:8e01d0b-r50-lab:
1. Core companies base table migration 2023_04_25_094305;
2. Core users base table migration 2023_04_25_094301;
3. Core api_credentials base migration 2023_04_25_094311;
4. Sanctum base personal_access_tokens migration 2019_12_14;
5. Core UUID update for personal_access_tokens 2023_04_25_094311;
6. Core Navigator credential scope migration 2026_11_11_000002;
7-8. FleetOps real Navigator token and pending-pairings migrations.
This is eight actual relevant migrations, NOT a full replay of
all Core API/FleetOps/extension migrations, FKs or production data.
Laravel cache/log/activity service bindings still use synthetic mocks.

## Dynamic MySQL security verification
security-tests/r76-core-schema-revoke.php:
74/74 PASS, exit 0, actual Eloquent User, Company, ApiCredential,
Sanctum PersonalAccessToken, ApiCredentialObserver,
NavigatorCredentialScope and actual FleetOps token/credential services.
Includes real positive final credential claim with correct issuer tenant,
P-256 two signatures/admin approval, QR and claim replay resistance;
nested transaction forced rollback prevents orphan device and credential;
two independent PHP processes racing for one pairing serialize and issue
exactly one credential; invalid/expired/overlong/missing scope/device
disabled/issuer disabled/cross-tenant cases denied.
New proof: bindDriverToken links real Sanctum token to parent credential,
inheriting its expiry, rejects and deletes cross-tenant driver tokens.
Revocation of issuer-owned credential soft-deletes the key and deletes
descendant driver sessions; cross-tenant and duplicate revocation refused.
Core scope migration DOWN fails closed with scoped credentials, including
soft-deleted rows, and fails with an orphan descendant token reference.
Explicit lab-only purge permits clean DOWN of all eight migrations.

## Source-only security fix
server/src/Support/NavigatorCredentialService.php now checks
$credential->trashed() on the revoke code path. Without this, second
revocation accepted a previously soft-deleted credential. R76 retest:
SECOND_REVOKE_FAILS_CLOSED PASS.
No production change.

## HTTP route and provisioning-scope tests
- Real FleetOps source route registration captured via in-memory
  Laravel Route facade test recorder: Pest 4 tests / 75 assertions
  PASS. Admin issue/approval/list/revoke/rotate routes require
  fleetbase.protected, with per-operation throttles; device proof/
  redeem/status/claim only POST with throttle. Default FleetOps config
  prefix null resolves /int/v1/fleet-ops/navigator/*, matching the
  client URL (package route path; outer app/web-server prefixes can
  still vary).
- Real Laravel Request and Route objects tested against actual
  Fleetbase NavigatorCredentialScope::allowsBootstrap in pinned
  Core API code: 14/14 PASS. Only POST driver login/SMS/verify-code
  in driver-login mode and GET driver onboarding settings allowed;
  admin, order, account creation and wrong methods denied.
- This does NOT execute the complete Laravel HTTP kernel, auth
  session middleware pipeline, rate-limit buckets, CSRF/Origin, or
  externally accessible end-to-end HTTP requests. DO NOT claim so.

## Regression and environment
Controller Pest: 12/12 tests, 49 assertions PASS (mock collaborators).
Static source gates: 18/18 R76 + historical R71 28/28, R72 10/10
and R75 11/11; PHP syntax/generic diff checks PASS.
No Android build; Mac SDK and mobile clone node_modules absent.
Mac swap ~12 GiB allocated, only ~8.5 GiB free at preflight.
Avoid Android dependency installation under current resource pressure.
USB Android version rc1-qa unchanged. Mobile V2 feature OFF.

## Remaining critical release blockers
Complete app migrations/schema including all extensions, full Laravel
HTTP auth/Origin/CSRF/rate-limit execution, Plesk proxy URI privacy,
real Ember console acceptance, real Android Keystore/Keychain signing
and storage test, the QR-to-final-claim-to-SDK UI wiring, remote device
revocation UX, legacy MMKV driver-token remediation, FCM/WSS, QA
deployment and rollback acceptance. DO NOT MERGE/DEPLOY.
PRODUCTION NO-GO.
