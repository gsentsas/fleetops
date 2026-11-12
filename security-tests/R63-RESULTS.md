# GST FLEETBASE — S98-R63 — isolated Navigator V2 draft

Pinned fork base: 7f5a82f2d4be87f52bd5729e39384867af21345a
Local branch: feature/s98-r62-navigator-v2-isolated
No commit, push, CI, QA/production deploy, Plesk config change or DB mutation.

## API changes in isolated Git worktree
- Pairing issuance / redeem / rotation gated OFF by default by
  config('fleetops.navigator.pairing_v2_enabled', false) === true.
- Legacy GET pairing and GET issuance fail closed.
- Candidate POST issuance returns a one-time bearer secret in JSON body
  only; POST redeem consumes a JSON-body secret with query denied.
- Navigator token TTL reduced from 30 minutes to five minutes in service.
- API expiry metadata derives from controller constant (300 sec).
- Issued credential validated within redeemWith transaction; failed
  validation throws before consume commits (rollback intended).
- No credential or bearer token placed in the URL, browser redirect
  or Android/iOS intent URL by the V2 controller.

## Test changes
- Replaced outdated GET/deep-link acceptance expectations with 8 Pest
  contract cases covering flag OFF, GET denial, protected issuance,
  POST JSON body, replay, invalid format/query/content type,
  scoped credential rejection and existing organization lookup.
- php -l: controller, service, route, test file ALL PASS.
- git diff --check PASS.
- Static source-level guards: 20/20 PASS.
- No actual Pest/MySQL suite run: isolated fork has no vendor autoload
  and existing QA image has vendor but no test-runner binaries.
- Existing real MySQL security tests in PR #11 have not been rerun
  against this modified fork.

## CRITICAL remaining release blockers
- Device trust binding is NOT implemented: a QR bearer held by an
  attacker might be redeemed using POST. Device ID alone is NOT
  cryptographic proof of device identity. Require trusted app/client
  possession proof, admin confirmation or equivalent approved model,
  and bind issued credentials to validated device identity.
- Mobile source/build not modified; current QR/GET/deep link flow is
  intentionally incompatible with V2 and must not be switched on.
- Full HTTP 401/403/429/500 and atomic MySQL replay/tenant/revoke
  tests; app + proxy + Plesk canary log tests; HTTPS/WSS/FCM and
  Android/iOS device tests; production rollback are outstanding.
- Feature flag MUST stay OFF until all gates above pass. No release GO.

## Next stage S98-R64
- Prepare disposable isolated PHP/MySQL integration harness for this
  exact fork SHA, no QA database, no production network/service usage.
- Execute updated Pest cases, verify transaction/TTL/anti-replay,
  validate feature OFF on real routes.
- Implement device binding after mobile/client protocol review, with
  negative security tests and no URL keys.
