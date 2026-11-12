# GST Fleetbase S98-R73 - local QR and mobile Keychain adapter

## Scope
FleetOps local branch feature/s98-r62-navigator-v2-isolated,
base SHA 7f5a82f2d4be87f52bd5729e39384867af21345a.
Mobile independent branch feature/s98-r70-navigator-v2-local,
base SHA 0479fdb061955448832c1a8a523d96381f2c8f8b.
No commit/push/merge/QA or production deploy.

## Browser QR
- New qrcode dependency pinned exactly 1.5.4 in package.json,
  and minimal pnpm-lock.yaml update: 10 extra package entries and
  matching snapshots, original transitive peer resolutions retained.
- An initial pnpm lock regeneration changed ~3200 unrelated lines;
  that excessive change was discarded. Offline frozen lockfile
  verification PASS, supply-chain policy verification PASS.
- Ember Navigator App settings panel creates 64-hex single-use QR via
  authenticated POST, encodes PNG entirely locally with qrcode library.
- No external QR service, no navigable URL, no clipboard/logs.
- Image removed on dismissal, destruction, or at most 300s expiry.
  Note: dismissal hides the still-valid token; only expiry/use
  invalidates it server-side.
- Node qrcode + pngjs + jsqr synthetic round-trip proof 4/4 PASS,
  browser-local qrcode source guards 11/11 PASS, JS/HBS syntax
  2/2 PASS. No actual Ember app build/browser test.

## Android Keychain adapter
- New source mobile src/services/navigator-v2-secure-credentials.ts.
- Uses react-native-keychain 10.0.0 already pinned in mobile yarn.lock.
  Requires Android secure hardware encryption and dedicated app
  Keychain service, iOS device-only unlock accessibility.
- Validates trusted HTTPS origin, pinned socket hostname and WSS,
  malformed credentials, and no control chars. No MMKV persistence,
  no credentials in URLs, logs, or QR code.
- Strict TypeScript typecheck PASS with actual v10 .d.ts definitions
  and synthetic React Native NativeModules declaration.
- 13/13 contract assertions with MOCK Keychain PASS including
  storage failure, restore, incorrect host, clearing credentials.
- NOT linked to native app SDK, login state or final claim yet;
  pre-existing manual legacy FLEETBASE_KEY is still stored in MMKV,
  so full mobile app is NOT secure and NO-GO must remain.

## Not completed
- Real AndroidKeyStore/Keychain behavior, Gradle Kotlin compile,
  native React Native build, APK installation, physical device test.
  Mac has no Android SDK directory or app clone node_modules and
  about 10 GiB spare capacity. Avoid large automatic downloads.
- Full Core API/credential issuance and secure SDK handoff/migration,
  actual Ember UI, HTTP middleware, Plesk privacy, WSS/FCM.
- V2 flag remains OFF. Production NO-GO.
