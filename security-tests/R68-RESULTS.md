# GST Fleetbase S98-R68 - Real migration and parallel redeem

Local isolated fork: gsentsas/fleetops pinned
7f5a82f2d4be87f52bd5729e39384867af21345a.
Branch feature/s98-r62-navigator-v2-isolated, still uncommitted.

## Actual test
Disposable MySQL 8.0 InnoDB on docker internal network gst-r68-internal,
not exposed to the host. Separate read-only PHP container with source
from isolated fork. Laravel schema facade runs the actual migration
2026_11_11_000001_create_fleetops_navigator_link_tokens_table.php.
Real NavigatorLinkTokenService PHP source handles issue/redeem.
Synthetic standalone Eloquent Company/User tables and model substitutes
are necessary for this focused isolated test.

Two PHP child processes fork and each open fresh MySQL connection;
one holds transaction lock for ~350ms; exactly one receives tenant B
while other receives a rejected replay. Seven validation gates PASS:
migration up, expected index structures, issue, true parallel winner,
single consumed row, post-race replay denial, migration down.

## Important limits
- Full Fleetbase application model/migration set NOT replayed.
- One two-process race case only; not an exhaustive stress test.
- HTTP middleware authorization, other companies/roles, Android
  device proof, WSS/FCM and Plesk error log QA remain open.
- The feature remains OFF in local uncommitted candidate.
- Do not infer production-readiness from focused integration tests.

## Cleanup
MySQL container and private Docker network stopped/removed, verified.
QA 4/4 healthy and production 8 containers running.
No commit, push, merge, config reload or deploy. NO-GO.
