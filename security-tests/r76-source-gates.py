from pathlib import Path
routes=Path('server/src/routes.php').read_text()
service=Path('server/src/Support/NavigatorCredentialService.php').read_text()
guard=Path('server/src/Support/NavigatorDatabaseGuard.php').read_text()
scope=Path('security-tests/r76-real-route-scope.php').read_text()
core=Path('security-tests/r76-core-schema-revoke.php').read_text()
route_tests=Path('server/tests/Feature/Http/FleetOpsRouteRegistrationTest.php').read_text()
controller=Path('server/src/Http/Controllers/Internal/v1/NavigatorController.php').read_text()
checks={
 'NO_SECOND_REVOKE': '$credential->trashed()' in service,
 'CROSS_TENANT_REVOKE': '$credential->company_uuid !== $actor->company_uuid' in service,
 'DESCENDANT_TOKEN_PURGE': "->where('navigator_credential_uuid', $credential->uuid)->delete()" in service,
 'REVOKE_TRANSACTION': 'DB::transaction(function () use ($actor, $credentialUuid)' in service,
 'REVOKE_ALIAS_GUARD': 'NavigatorDatabaseGuard::assertAtomic();' in service,
 'ACTIVE_SCOPE_VALIDITY': 'NavigatorCredentialScope::isNavigator($credential)' in service,
 'ADMIN_ISSUE_PROTECTED': "post('pairings', 'NavigatorController@issuePairing')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:5,1'])" in routes,
 'ADMIN_APPROVE_PROTECTED': "post('pairings/approve', 'NavigatorController@approvePairing')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:10,1'])" in routes,
 'CLAIM_POST_THROTTLED': "post('pairings/claim', 'NavigatorController@claimPairing')->middleware('throttle:10,1')" in routes,
 'REAL_CORE_MIGRATIONS': "core-api/migrations/2026_11_11_000002_add_navigator_credential_scope.php" in core and "core-api/migrations/2023_04_25_094311_create_api_credentials_table.php" in core,
 'REAL_SANCTUM_BIND': 'NavigatorCredentialScope::bindDriverToken($driverToken)' in core,
 'REAL_DRIVER_EXPIRY': 'CORE_DRIVER_TOKEN_INHERITS_PARENT_EXPIRY' in core,
 'CORE_DOWN_REFUSALS': 'CORE_SCOPE_DOWN_BLOCKS_ORPHAN_TOKEN_REFERENCE' in core and 'CORE_SCOPE_DOWN_BLOCKS_SOFT_DELETED_SCOPED_KEYS' in core,
 'PARALLEL_CLAIM_TEST': 'TRUE_PARALLEL_CLAIM_ONE_WINNER' in core,
 'REVOKE_DOUBLE_DENIAL_TEST': 'SECOND_REVOKE_FAILS_CLOSED' in core,
 'REAL_LARAVEL_ROUTE_OBJECTS': 'new Route(' in scope and 'allowsBootstrap(' in scope,
 'DEFAULT_PREFIX_PARITY': 'navigator V2 default FleetOps routes match' in route_tests,
 'V2_FEATURE_OFF': "config('fleetops.navigator.pairing_v2_enabled', false) === true" in controller,
}
for name,yes in checks.items():
 print('R76_STATIC_'+name+'='+('PASS' if yes else 'FAIL'))
assert all(checks.values()),'R76_STATIC_CHECKS_INCOMPLETE'
print('R76_SOURCE_GATES=18/18')
