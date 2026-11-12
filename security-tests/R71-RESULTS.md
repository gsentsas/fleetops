# GST FLEETBASE - S98-R71 - Pending QR and Android device proof (LAB ONLY)

Base fork gsentsas/fleetops SHA
7f5a82f2d4be87f52bd5729e39384867af21345a.
Local branch feature/s98-r62-navigator-v2-isolated.
No commit, push, merge, release, QA migration or deployment.

## New API source
- NavigatorPairingV2Service.php: begin, prove, approve, status, claim.
- New FleetOps pending_pairings migration with public P-256 key, challenge,
  signed proof/claim states and explicit issuer-admin approval.
- Controller redeems QR into PENDING only, NEVER directly into API credential.
- POST /pairings/proof, /pairings/status, /pairings/approve, /pairings/claim.
- Approval is protected by fleetbase.protected and exact issuer tenant.
- Final claim requires another signature using the same P-256 public key,
  after both verified_at and approved_at; scoped credential is returned in
  HTTPS response JSON only, never Location or a deep link.
- Session max 5 minutes, challenge max 2 minutes, crypto verifier rejects
  wrong key/tenant/expired/replay; original legacy GET fails closed.
- API V2 feature flag pairing_v2_enabled FALSE by default.
- OpenSSL expects DER ECDSA signatures encoded as Base64, SHA-256/P-256.
- No real device attestation or verified physical device identity.
  Admin must compare fingerprint with device before approving.

## Verification
- Real modified controller Pest: 10 tests / 43 assertions PASS on cached
  Laravel API image in network-none readonly Docker using synthetic
  response factory, issuer, token and pairing collaborators.
- Isolated MySQL 8 (real Eloquent User and Company models with minimal
  synthetic User/Company tables) and both actual token/pending migrations:
  21 / 21 dynamic checks PASS, including QR-only pending, wrong/valid
  P256 signature, proof replay, cross-tenant approval denial, required
  issuer approval, second-signature rejection, pending-session expiry,
  QR token replay denial and migration down.
- Source static guards: 28/28 PASS; php -l and git diff --check PASS.
- Android source clone is independent, no APK installed or updated.

## Mobile clone
/Users/gsent/Documents/ChatGPT/FLEETBASE/s98-r70-navigator-v2-lab
base local SHA 0479fdb061955448832c1a8a523d96381f2c8f8b.
Kotlin AndroidKeyStore P-256 native bridge and ReactPackage added.
New TypeScript HTTPS POST client and canonical proof/claim message.
Legacy configure?key link handlers disabled ONLY in mobile clone.
TypeScript 5.0.4 via isolated VPS runner with lightweight react-native
NativeModules type stub: static typecheck PASS; canonical JS message
5/5 PASS. This is not a full React Native bundle/build.
Mac Homebrew Node 26 is broken due missing simdjson .dylib; not altered.

## Open critical release gates
- Android Kotlin module NOT compiled; no test on actual AndroidKeyStore.
- No QR scan UI, no administrator approval screen/verification UX;
  client service exists but is not wired to an application screen.
- Claim positive issuance via actual NavigatorCredentialService and
  production User/ApiCredential schema NOT tested. Current MySQL test
  proves invalid/unapproved claim cannot issue, not the full happy path.
- Production schema replay and middleware/CSRF/Origin/rate-limit not
  validated, crypto key pinning/rotation/attestation review pending.
- No secure scoped credential persistence UI; legacy manual key input
  is still available outside the disabled deep-link handlers.
- Plesk Nginx error logs still capable of query/Referer leakage;
  WSS/FCM/Android business recipe and rollback not completed.
- Admin must identify an intended pending session from a protected UI
  and compare device fingerprint before approval (not built).
- No product QA/production enablement. PROD NO-GO.

## Cleanup
Disposable gst-r71-mysql and gst-r71-internal network removed.
QA 4/4 healthy and prod gstfleet 8 containers up.
