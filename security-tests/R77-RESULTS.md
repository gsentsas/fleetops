# GST FLEETBASE S98-R77 - Real Laravel HTTP kernel and Plesk privacy audit

## Provenance and change control
Server lab /opt/gst-rc1-staging/s98-r62-fleetops-v2,
branch feature/s98-r62-navigator-v2-isolated,
original Git SHA 7f5a82f2d4be87f52bd5729e39384867af21345a.
Android clone original SHA 0479fdb061955448832c1a8a523d96381f2c8f8b.
No commit/push/merge, no live QA/production access, no Plesk changes,
no phone APK installation. Navigator V2 OFF in live environments.
Production NO-GO.

## Actual Laravel kernel bootstrap - PASS
Pinned image gst-rc1-api:8e01d0b-r50-lab, Laravel 12.71.0,
read-only Docker with tmpfs views/storage/cache.
Default app bootstrap/app.php and App Http Kernel, real Core API
service providers and complete registered route collection.
In isolated database-disabled check 11 Navigator routes registered,
V2 OFF by default. The first HTTP requests returned 503 from the
real EnsureFleetbaseConfigured middleware, as required when Core
tables/settings are missing. After five true Core API migrations
(users, companies, settings, api_credentials, Sanctum tokens) in a
disposable MySQL 8 DB, initial 503 setup block was removed.
The package's real Fleetbase Expansions Response::error callback
was manually registered in the lab because the normal extension
registration did not activate during this minimal test bootstrap.
No fake navigator controllers, no mock HTTP middleware; this
lab-specific registration is NOT an unmodified production boot.

## Real HTTP regression - feature OFF, 13/13 PASS
security-tests/r77-http-kernel-cases.php:
- GET legacy /link-app -> 403 no-store, no-referrer;
- GET legacy link with synthetic ?key= canary -> 403; token absent
  from response body;
- GET legacy /get-link-app anonymous -> 401;
- POST redeem/proof/status/claim when V2 OFF -> 403;
- anonymous POST issue/pending/approve and delete/rotate -> 401;
- GET to POST-only redeem route -> 405.
13/13 PASS, no canary reflected in response.
No credentials issued or stored in this test.

## Deliberate isolated V2-ON negative validation - 8/8 PASS
security-tests/r77-http-v2-invalid.php:
Feature flag changed only inside disposable PHP process, not in
source or live config. Invalid QR public key/token case, query
bearer in POST URL, invalid P-256 session proof/status/claim, and
legacy GET all refused with 403. Anonymous admin approve -> 401.
8/8 PASS. No successful authenticated issuer/claim HTTP journey.

## Actual Laravel ThrottleRequests - 3/3 PASS
security-tests/r77-http-throttle.php, per-IP array cache in one
real Laravel kernel and actual registered throttle middleware:
- redeem: 10 x HTTP 403, then 2 x HTTP 429;
- status: 20 x HTTP 403, then 2 x HTTP 429;
- claim: 10 x HTTP 403, then 2 x HTTP 429.
3/3 PASS. Repeated synthetic requests, not a distributed Redis
or across-worker test; production rate limiter persistence still
requires acceptance.

## Real CORS and forwarded-IP behavior - 3/3 PASS
security-tests/r77-http-cors-xff.php:
- allowed browser Origin http://localhost:4200 returns HTTP 204,
  Access-Control-Allow-Origin equal to that Origin, and credentials
  permission true;
- untrusted https://untrusted.synthetic.invalid receives HTTP 204
  with ACAO http://localhost:4200, which does NOT match its Origin
  and is rejected by a standards-compliant browser. The test
  initially (too strictly) expected ACAO to be absent; after
  correction to the actual browser Origin/ACAO comparison it passes.
  This static allowed-origin header still deserves review under
  the final production CORS configuration.
- repeating redeem with forged different X-Forwarded-For values,
  constant synthetic REMOTE_ADDR, retains effective one-client
  throttle: ten 403 then two 429. 3/3 PASS.
No browser with an authenticated admin cookie tested; CSRF/Origin
defenses in that high-risk context remain UNPROVEN.

## Read-only Plesk Nginx access log audit - NO-GO
security-tests/r77-plesk-access-log-audit.py found six active
access_log directives in three Fleetbase generated vhosts:
api-fleet.globalsentransport.fr, fleet.globalsentransport.fr,
ws-fleet.globalsentransport.fr; all lack a named redacted format.
Default nginx combined format includes the full request URI
and query string. This is a potential leakage route for legacy
query-bearing links, NOT evidence of actual secrets in existing logs.
No live log content, requests, canaries, reloads or edits.
nginx -t PASS read-only.
Proposal only in R77-PLESK-LOG-REDACTION-PROPOSAL.md.
Privacy gate stays OPEN/NO-GO.

## Regressions and maintenance
Pest Navigator controller 12 tests / 49 assertions PASS (fake
collaborators); route recorder 4 tests / 75 assertions PASS;
source gates R71 28/28 and R76 18/18 PASS; PHP lint and
git diff --check PASS. Android Keystore Kotlin/Keychain physical
tests and full React Native bundle NOT run. Mac disk only about
7 GiB available with heavy macOS swap; no SDK dependency install.

## Explicit release limitations
Complete Core API+extensions migration replay and authenticated
administrator session, active end-to-end positive issuance over
HTTP, production HTTP proxy/Origin/CSRF/cookie checks, distributed
rate limits and remote session revoke, browser Ember and physical
Android QR->Keychain->SDK, WSS/FCM, Plesk query/referrer/error log
privacy canaries and real QA rollback acceptance are NOT verified.
No feature activation, merge, release or deploy.
PRODUCTION NO-GO.

## Cleanup
Disposable MySQL gst-r77-mysql and Docker private network
gst-r77-internal must be removed after tests; check absence.
Only source harness and sanitized report retained.
Next S98-R78: isolated authenticated HTTP admin positive flow
and CSRF/Origin verification, production log redaction design
with approved test/rollback, then Android build on spacious host.
