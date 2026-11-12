# GST FLEETBASE S98-R72 - Admin review UI and mobile QR screen (LAB ONLY)

## Source pinning and isolation
FleetOps fork isolated worktree:
  /opt/gst-rc1-staging/s98-r62-fleetops-v2
  local branch feature/s98-r62-navigator-v2-isolated
  unchanged Git HEAD 7f5a82f2d4be87f52bd5729e39384867af21345a
Android clone:
  /Users/gsent/Documents/ChatGPT/FLEETBASE/s98-r70-navigator-v2-lab
  local branch feature/s98-r70-navigator-v2-local
  unchanged Git HEAD 0479fdb061955448832c1a8a523d96381f2c8f8b
No commit, push, merge, build artifact, QA or production deployment.

## API approval hardening
- NavigatorPairingV2Service::pendingForIssuer lists only sessions that have
  a verified signature, are not approved/claimed/expired, and belong to
  the exact company and issuing administrator. No QR secret, claim nonce,
  credential or private key in list response.
- A typed 12-char public-key fingerprint comparison is required by approve,
  verified server-side via constant-time hash_equals and exact issuer.
- New protected and rate-limited POST pairings/admin-pending endpoint.
- Protected POST pairings/approve requires deviceConfirmationCode.
- V2 feature flag still OFF by default; no prod behavior changed.

## Ember FleetOps approval UI
- addon/components/navigator-pairing-approvals.js/.hbs, added in the
  settings Navigator App template, inside existing permissions boundary.
- List pending devices, show short fingerprint, ask administrator to
  manually type code from physical phone, then confirmation modal.
- No automatic approval, no QR secret or device credential exposed.
- JS decorators parsed by Babel; Glimmer template and settings template
  parsed using @glimmer/syntax in isolated parser runner.
- UI static security gates 8/8 PASS. No actual Ember build/browser e2e.

## Android in-app QR screen
- New src/screens/NavigatorPairingQrScreen.tsx with VisionCamera QR scan.
- Screen wired via AppNavigator/CoreStack and InstanceLink settings button.
- Accept raw 64-hex transfer token only, reject navigable URL QR.
- Use trusted HTTPS host from app configuration, not QR; start POST
  pending proof; show 12-char fingerprint; check admin approval.
- Final credential claim intentionally NOT made in this screen until
  trusted secure-store and app SDK credential handoff are implemented.
- TypeScript/TSX syntax parse 6/6 PASS (TypeScript 5.0.4);
  mobile static checks 7/7 PASS; isolated client strict typecheck PASS.
- No Gradle/Android build: Mac SDK directory and clone node_modules absent,
  Gradle wrapper 8.13 not cached, local disk headroom only about 11GiB.
- Existing USB Android 16, QA app version rc1-qa unchanged.

## Tests run
- PHP controller Pest 12 tests, 49 assertions PASS in isolated Docker
  with mocked collaborators and synthetic response factory.
- Real Navigator pending service and migrations on disposable MySQL
  with actual User/Company PHP Eloquent models, minimal synthetic SQL
  User/Company tables: 25/25 dynamic gates PASS, including issuer list,
  cross-tenant privacy, wrong confirmation, single approval, signed proof.
- R71 source static 28/28 PASS; new R72 API/UI static 10/10 PASS.
- Ember JS/HBS parse 3/3 PASS; UI static 8/8 PASS.
- Mobile TS/TSX syntax 6/6 and static 7/7 PASS.
- No actual full Laravel HTTP kernel or React Native app build executed.

## Critical NO-GO gates
- QR generation/display in admin console NOT implemented: app can scan
  a raw QR only, no end-to-end UI to produce it on the console.
- True Android Keystore native Kotlin module NOT compiled/run on device.
- Manual approval UX and fingerprint matching NOT device-tested.
- Successful final credential issuance, scoped secure-store persistence
  and app SDK session restoration NOT integrated/e2e-tested.
- Full Core API migrations/schema, production middleware/CSRF/Origin,
  race on final claim, Plesk error log privacy and WSS/FCM still open.
- Client/console feature must remain disabled and not deployed.

## Cleanup
Disposable MySQL container gst-r72-mysql stopped with --rm and
private network gst-r72-internal deleted and absence verified.
QA 4/4 healthy, production gstfleet 8 running. NO-GO.
