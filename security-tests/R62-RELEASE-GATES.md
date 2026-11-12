# S98-R62 — First Navigator V2 API patch; ISOLATED DRAFT / NO-GO

Base commit: 7f5a82f2d4be87f52bd5729e39384867af21345a
Local branch: feature/s98-r62-navigator-v2-isolated
Worktree: /opt/gst-rc1-staging/s98-r62-fleetops-v2
Repo files changed: NavigatorController.php, routes.php, new static checker.
Not committed, not pushed, no CI, no composer lock change, no QA deployment.

## Implemented API draft
- Protected POST pairings issues 32-byte random transfer secret as JSON response.
- Public rate-limited POST pairings/redeem accepts JSON body only, rejects
  request query and bad token format, delegates to existing atomic one-time
  tenant/issuer-bound token redemption.
- Success returns existing scoped Navigator credential in HTTPS response BODY
  only, with no-store/referrer-policy headers, never in deep link URL.
- Legacy GET link-app and GET get-link-app intentionally fail closed.
- Protected rotatePairing now returns pairingSecret in JSON body rather than
  a URL containing bearer token.
- Security static checks 14/14 PASS; PHP syntax PASS; git diff --check PASS.

## Current BLOCKERS — do not publish candidate as release
1. Existing NavigatorControllerContractsTest.php expects GET deep-link URLs
   containing key. Its tests are incompatible by design; adapt and run
   complete feature tests and transactional multi-tenant MySQL.
2. No runtime PHPUnit/Pest executed from isolated fork: vendor dependencies
   not installed in this fork worktree.
3. Mobile Navigator source/build not modified or verified: current mobile
   client scans GET URLs and accepts key in intent URL; protocol incompatible.
4. No verified device-binding/attestation. Returning a scoped credential
   to an unauthenticated POST redeemer who holds a QR bearer remains a
   meaningful threat, even with one-time secret and 30-minute expiry.
5. Need tighten issue token TTL (V2 contract draft 5min vs existing 30min),
   rate limit semantics, trusted HTTPS/proxy enforcement, CSRF/origin split
   for browser and native clients, anti-exfiltration checks.
6. Confirm deep-link QR behavior: secret scanned by Navigator app, NOT
   opened by generic browser. Add pairing UX confirmation and safe retry.
7. Never log request bodies/credentials; test 401/403/429/500 and canaries
   across Plesk/Nginx/Apache/FleetOps/Android crash logs.
8. Validate migration fail-closed and backward-compatibility policy;
   ensure current QA client is not accidentally pointed to V2 API.
9. Complete Plesk render + rollback and device/FCM/WSS acceptance.

## Why no commit/push
This is the FIRST implementation spike only. Failing old test expectations,
missing runtime tests and missing mobile client integration preclude CI GO.
Keep repo isolated and QA/prod untouched.
