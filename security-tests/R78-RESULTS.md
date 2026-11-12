# S98-R78 — authenticated administrator HTTP validation

33/33 real wire HTTP controls pass. See R78-HTTP-RESULT.json. Laravel 12.71.0,
PHP 8.2.35, full identity-empty R29 archive restored then migrated 395→398,
MySQL 8 and phpredis/Redis, two independent HTTP API containers, internal
network, no published ports. APP_ENV=lab avoids Laravel's testing CSRF bypass.

The lab binds the actual Request before kernel providers boot. R77's eager
bootstrap skipped facade expansions; the new router does not manually register
macros. Only the feature flag and allowed test origins are runtime overrides.
Core candidate AuthController/config files are read-only mounts, not active
vendor patches. Fixture credentials, cookies, QR and software P-256 keys are
synthetic. Android hardware and HTTPS/TLS transport are NOT tested by this lab.

Fixes: V2 administrator operations require explicit Sanctum bearer authority,
JSON without query and exact trusted Origin if present; ambient cookies and
untrusted/suffix-spoof Origins cannot approve. The normal console password login
issues a bounded token (120 minutes default, 1–1440 configured, lower Sanctum
expiration respected), rejects inactive accounts and expired authToken reuse.
Logout revokes the exact bearer and related cache entries; 200 is followed by
401 when the same bearer is reused. Other console issuance paths (SSO/2FA) and
legacy unbounded tokens still need a lifecycle review before release.

Positive QR→pending→proof→issuer approval→second proof→credential, claim replay,
tenant isolation, provisioning restrictions, HTTP revoke and duplicate revoke
are covered. Cookie flags are Secure/HttpOnly/SameSite=Lax. Cookies from this
bearer console flow alone give no authority. This is not a claim that a separate
web cookie-login/CSRF flow exists or passed. Redis limits across two processes:
10 refusals then 2 HTTP 429 with one actual client IP. Existing R76 concurrent
claim evidence remains separate; this milestone does not relabel it as new.

Earlier attempts: Predis rejected suffixed nonnumeric Redis DB identifiers;
phpredis is the actual image default and was used. Wrong test bootstrap caused
missing Request/Response macros; corrected before positive auth. No-store is
checked as a directive in the compound Cache-Control header; claimChallenge is
the real response key. A first multi-process limiter test accidentally used two
client IPs; corrected, no limiter weakening. These failed attempts are not PASS.

All sensitive synthetic values are absent from both runtime logs. All four
containers/network removed; protected 8 production / 4 QA snapshots identical.
No production or active QA mutation, no public canary, no APK installation.
V2 remains OFF outside the lab. Release NO-GO. Code is being prepared for
reviewed commits/PR and exact-SHA CI; this report alone is not that CI.

Historical source guards were updated to require the new admin middleware in
addition to auth and throttle; their old exact two-item array checks initially
failed. Updated checks pass R71 28/28, R72 10/10, R75 11/11, R76 18/18.
These checks remain supplemental to the 33 actual HTTP controls. PHP lint passed
37 candidate files. Gitleaks 8.30.1 checksum verified: three redacted alerts,
explicit synthetic fixture and two unchanged baseline non-secret/test constants;
raw exit 1 retained, no blanket allowlist added.
