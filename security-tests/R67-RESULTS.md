# GST Fleetbase S98-R67: real service integration MySQL

Fork baseline: 7f5a82f2d4be87f52bd5729e39384867af21345a.
Local uncommitted branch feature/s98-r62-navigator-v2-isolated.

Disposable MySQL 8 InnoDB on dedicated Docker internal network,
never exposed to public or host ports. Real edited
NavigatorLinkTokenService with Laravel Eloquent, DB facade,
and synthetic User/Company Eloquent models and minimal schema.
No QA or production DB queried.

10/10 actual service checks PASS:
hash-at-rest; company/issuer binding; TTL 5min; first redeem for
company B; replay denied; exception transaction rollback;
retry after rollback; expiry denied; inactive issuer denied;
forged secret denied.

Limitations: synthetic Eloquent user/company models and tables,
not full Fleetbase schema/migrations. No concurrent two-process
race check, no real HTTP middleware/rate-limit, no mobile device
binding nor actual app credential issuance. Feature OFF.
The harness initially needed PHP autoload and DB facade setup
corrections; final 10/10 exited 0.

Disposable MySQL stopped and removed; Docker network removed.
QA healthy 4/4, prod containers 8. No commit, push, merge or deploy.
PRODUCTION NO-GO. Next R68: concurrent real service + full schema,
device authentication and QA protocol.
