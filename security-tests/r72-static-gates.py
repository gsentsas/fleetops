from pathlib import Path
c=Path('server/src/Http/Controllers/Internal/v1/NavigatorController.php').read_text()
s=Path('server/src/Support/NavigatorPairingV2Service.php').read_text()
r=Path('server/src/routes.php').read_text()
e=Path('addon/components/navigator-pairing-approvals.js').read_text()
t=Path('addon/components/navigator-pairing-approvals.hbs').read_text()
settings=Path('addon/templates/settings/navigator-app.hbs').read_text()
tests={
 'EXACT_ISSUER_AND_COMPANY_FILTERS': "->where('issuer_user_uuid', $issuer->uuid)" in s and "->where('company_uuid', $issuer->company_uuid)" in s,
 'ONLY_VERIFIED_UNAPPROVED_LISTED': "->whereNotNull('verified_at')" in s and "->whereNull('approved_at')" in s and "->whereNull('claimed_at')" in s,
 'MATCHED_CODE_CONSTANT_TIME': "hash_equals(substr($row->key_fingerprint, 0, 12), $deviceConfirmationCode)" in s,
 'ISSUER_APPROVAL_REQUIRES_TYPED_CODE': "$request->json('deviceConfirmationCode')" in c and "->approve($actor, $session, $confirmation)" in c,
 'PROTECTED_POST_ADMIN_LIST': "post('pairings/admin-pending', 'NavigatorController@pendingPairings')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:10,1'])" in r,
 'PROTECTED_POST_APPROVE': "post('pairings/approve', 'NavigatorController@approvePairing')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:10,1'])" in r,
 'ADMIN_UI_MOUNTED': '<NavigatorPairingApprovals />' in settings and "navigator-pairing-approvals" in str(Path('addon/components/navigator-pairing-approvals.js')),
 'UI_CANNOT_AUTO_CONFIRM': "this.confirmation === this.selected.deviceConfirmationCode" in e and "typeConfirmation" in t and "requestApproval" in t,
 'UI_DOES_NOT_SHOW_SECRET': "pairingSecret" not in t and "claimChallenge" not in t and "credential" not in t,
 'V2_DEFAULT_OFF': "config('fleetops.navigator.pairing_v2_enabled', false) === true" in c,
}
for k,v in tests.items():
 print('R72_'+k+'='+('PASS' if v else 'FAIL'))
assert all(tests.values())
print('R72_API_AND_UI_STATIC=10/10')
