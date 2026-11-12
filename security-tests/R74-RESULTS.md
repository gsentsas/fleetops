# GST Fleetbase S98-R74 - SDK handoff and safe legacy key migration LAB

## Scope
Server FleetOps isolated fork base SHA
7f5a82f2d4be87f52bd5729e39384867af21345a.
Mobile clone base SHA 0479fdb061955448832c1a8a523d96381f2c8f8b.
No commit, push, merge or deploy. Production and QA untouched.

## Mobile source written
- navigator-v2-release-gate.ts: hard OFF gate.
- use-navigator-v2-device.ts: async Keychain reader with states
  off, loading, absent, invalid, ready. No MMKV fallback.
- use-fleetbase.ts: V2 secure SDK bootstrap behind disabled gate.
  Missing/invalid Keychain denies SDK bootstrap; preserves separate
  driver token preference only when a V2 device is ready.
- navigator-v2-migration.ts: purge legacy MMKV key only AFTER reading
  back secure V2 key from Keychain. Never convert legacy admin API key
  into scoped V2 credential. Local unlink clears secure vault before
  the two MMKV legacy API-key slots. Does not revoke server key.
- No claim/store via QR screen in this stage. Legacy key handling
  remains present in app when V2 gate is OFF.

## Verification actually run
- Strict TypeScript 5.0.4 with synthetic React/Fleetbase SDK stubs and
  real react-native-keychain 10.0.0 declaration types: PASS.
- SDK bootstrap mock execution: 11/11 PASS (V2 absent/invalid no
  legacy fallback, V2 ready uses Keychain value + trusted host,
  separate driver token preference, OFF keeps legacy behavior).
- Secure migration mock execution: 8/8 PASS (no data deletion without
  verified secure read, no silent promotion, explicit clear behavior).
- Real pending Navigator service regression on disposable MySQL 8:
  25/25 PASS using actual Eloquent Core User/Company classes and
  minimal synthetic User/Company SQL tables plus FleetOps migrations.
- Positive final scoped credential issuance NOT executed. A test
  preparation attempt was rejected by a tool safety control; do not
  count it as passing. Real ApiCredential schema/observer untested.
- PHP lint, source gates, git diff --check: PASS. QA 4/4 healthy,
  production 8 running. Test MySQL/network removed, no residue.

## Open NO-GO
- Actual ApiCredentialObserver/issuance positive case, server token
  replay and full-schema/HTTP middleware CSRF/Origin tests.
- SDK connection rebuild and real secure vault on physical Android,
  Kotlin bridge Gradle build, mobile QR UI integration, credential
  lifecycle/revocation and driver token currently in MMKV.
- Plesk error log privacy; FCM/WSS/rollback QA not accepted.
- V2 remains OFF. NO-GO production.
