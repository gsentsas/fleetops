# GST Fleetbase S98-R64 — Controller runtime in isolation

Source fork pinned at 7f5a82f2d4be87f52bd5729e39384867af21345a.
Branch feature/s98-r62-navigator-v2-isolated; no commit/push/merge/deploy.

## Runtime executed
- PHP 8.3 standalone controller loaded from actual edited PHP source with
  minimal fake Laravel request, response, user, credential, scope and
  token-service collaborators. No Fleetbase QA database or network accessed.
- 14 real PHP method executions/assertions: fail-closed GET, feature flag
  OFF, anonymous issue 403, admin issue 300s, no link URL, JSON-only
  redemption, reject URL query, malformed secret, body-only credential,
  no Location and no-store, replay denied, invalid credential fail closed.
- Static source checks 20/20 PASS; php -l relevant files PASS;
  git diff --check PASS.
- Framework feature test NOT performed: no PHPunit/Pest binary in available
  cached runtime image and no vendor install in isolated fork.

## Limitations / remaining blockers
- Fake token service is NOT MySQL; tests do not validate transaction locks,
  concurrent redeem, tenant RLS, real middleware, or migrations.
- Fake Request does not test Laravel's actual request parsing, HTTP routing,
  CSRF, origin/forwarded headers or rate limiting.
- No device attestation/proof of possession. QR secret bearer by itself is
  still redeemable by an attacker who sees it.
- Native Android/iOS client not adapted or installed.
- This draft is protected by feature flag OFF by default, NOT production ready.
- Plesk error log can still record sensitive URL queries in other workflows.
- Require disposable vendor/Pest and MySQL harness, exact SHA tests, mobile
  client and full error-log canaries before commit/push/QA approval.

No QA/production container modified or restarted. No Plesk override active.
