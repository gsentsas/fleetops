# GST Fleetbase S98-R75 - Actual final scoped credential, replay, rollback and SDK handoff

## Exact lab provenance
FleetOps isolated repo /opt/gst-rc1-staging/s98-r62-fleetops-v2
base SHA 7f5a82f2d4be87f52bd5729e39384867af21345a.
Mobile clone Mac /Users/gsent/Documents/ChatGPT/FLEETBASE/s98-r70-navigator-v2-lab
base SHA 0479fdb061955448832c1a8a523d96381f2c8f8b.
Branches remain uncommitted and unpushed; no release/merge/deploy.
Do not change live QA, production, Plesk or Android APK.

## Real PHP service final claim - 47/47 dynamic checks PASS
On isolated private Docker network gst-r75-internal with disposable
MySQL 8 instance gst-r75-mysql, no host port, the harness loads:
- actual Fleetbase Core PHP User, Company and ApiCredential Eloquent models;
- real FleetOps token and pending-pairing migrations and actual
  NavigatorLinkTokenService, NavigatorPairingV2Service,
  NavigatorCredentialService, NavigatorDatabaseGuard;
- real Fleetbase ApiCredentialObserver, generated scoped credential
  metadata and NavigatorCredentialScope::valid.
- synthetic minimal SQL Users/Companies/ApiCredentials schema and
  synthetic Laravel bootstrap cache/log/activity mocks. NO real
  business data, secrets or QA/production DB touched.

Positive path: issuer-B creates QR, device signs P-256 proof challenge,
exact administrator approves, device signs claim challenge, actual
service creates exactly one scoped Navigator credential for tenant B.
A replay cannot generate another credential.

Forced failure path: after actual nested credential issuance an injected
exception aborts the outer claim transaction; no orphan device or
credential rows remain and the pairing is retryable. A fresh retry
creates exactly one credential and denies a second claim.

True parallel claim path: two forked PHP processes with independent
PDO connections request the SAME approved session, one of them holds
the MySQL row lock for ~230ms. Exactly one returns a credential and
the other is denied. There is a single new credential row; subsequent
replay is denied. 47/47 gates PASS, process exit 0.

## Important test-bootstrap failure discovered and corrected
Initial harness used DB facade default alias "default" while the
Fleetbase User model hardcodes "mysql" and ApiCredential followed a
configurable alias. Thus nested transactions did not cover all model
writes. The negative test exposed orphan rows when a synthetic
exception was raised. This was a TEST-HARNESS connection mismatch,
not verified evidence of production behavior.
The final passing harness explicitly binds all three to the same
logical "mysql" connection, with a real transaction manager, actual
observer, and synthetic infrastructure bindings.
Added NavigatorDatabaseGuard::assertAtomic() in the isolated FleetOps
code, invoked before final claim issuance and before
NavigatorCredentialService issue/revoke, to fail closed on alias drift.
3/3 standalone guard scenarios PASS: aligned, bad default, bad
ApiCredential connection. This safeguard has not been deployed.

## Mobile claim-to-Keychain integration (source only, feature OFF)
New Mac file src/services/navigator-v2-claim-to-vault.ts implements:
- check hard OFF feature gate and valid pending session;
- refuse overwriting existing secure scoped credential;
- after two proofs and admin approval, request final claim exactly once;
- write returned credential to Keychain using trusted HTTPS/WSS inputs;
- re-read and compare exact credential before cleaning MMKV legacy key;
- on secure hardware storage/readback failure do not purge old MMKV and
  do not silently retry the already consumed server claim;
- explicit error requiring administrator revocation and new pairing.
The coordinator is NOT yet wired to the QR screen or real SDK.
Strict TypeScript typecheck with React/Fleetbase stub declarations
and actual react-native-keychain 10 types: PASS.
Node execution with mocked client/Keychain/migration: 12/12 PASS.
Existing mock SDK handoff 11/11, MMKV migration 8/8,
mock Keychain 13/13, console QR source 11/11 PASS.

## Regression
Navigator Controller Pest in isolated Docker: 12 tests, 49
assertions PASS. Existing source gates R71 28/28 and R72 10/10,
new R75 source gates 11/11, PHP lint and git diff --check PASS.

## Remaining blockers and NO-GO
- Full Core API production migrations/schema not replayed.
  The user/company/api credential SQL tables in R75 are minimal and
  the cache/log/activity app services are synthetic.
- Real HTTP controller/middleware/authorization/CSRF/Origin/rate limit
  not tested in complete Laravel kernel.
- Android native Keystore P-256 bridge not compiled or installed;
  connected Android QA version rc1-qa unchanged, Mac SDK/dependencies
  absent and free disk ~11 GiB.
- No complete device QR UI to Keychain claim/SDK restart/rotation UX;
  coordinator intentionally unconnected while feature hard OFF.
- Claims issued before failed local Keychain write cannot be
  automatically recovered; admin-side remote revocation flow required.
- Existing legacy API-key and driver token MMKV handling still present;
  full migration/remote revocation not complete.
- Full Plesk error-log privacy, WSS/FCM, business acceptance and
  QA rollback still open. PR #11 DRAFT unchanged.

## Cleanup
Disposable MySQL 8 container and internal network removed and
absence confirmed. QA 4/4 healthy, production gstfleet 8 running.
No commit/push/merge/QA deploy/production changes.
PRODUCTION NO-GO. Next R76: full Core API migration/schema,
real isolated HTTP kernel and secure Android device build/flow.
