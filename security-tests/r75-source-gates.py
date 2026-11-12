from pathlib import Path
guard=Path('server/src/Support/NavigatorDatabaseGuard.php').read_text()
pair=Path('server/src/Support/NavigatorPairingV2Service.php').read_text()
credential=Path('server/src/Support/NavigatorCredentialService.php').read_text()
controller=Path('server/src/Http/Controllers/Internal/v1/NavigatorController.php').read_text()
test=Path('security-tests/r75-claim-aligned.php').read_text()
checks={
 'DB_GUARD_CHECKS_DEFAULT': 'DB::getDefaultConnection()' in guard,
 'DB_GUARD_CHECKS_ISSUER': '(new User())->getConnectionName() !== $connection' in guard,
 'DB_GUARD_CHECKS_CREDENTIAL': '(new ApiCredential())->getConnectionName() !== $connection' in guard,
 'DB_GUARD_FAIL_CLOSED': "throw new \\RuntimeException('Navigator database connection mismatch.')" in guard,
 'PAIR_CLAIM_DB_GUARDED': pair.index('NavigatorDatabaseGuard::assertAtomic();') < pair.index('app(NavigatorCredentialService::class)->issue($issuer)'),
 'SERVICE_ISSUE_DB_GUARDED': credential.index('NavigatorDatabaseGuard::assertAtomic();') < credential.index('return DB::transaction('),
 'SERVICE_REVOKE_DB_GUARDED': credential.count('NavigatorDatabaseGuard::assertAtomic();')==2,
 'CLAIM_REQUIRES_TWO_PROOFS': '!$row->verified_at || !$row->approved_at' in pair and "self::signatureMatches($row, 'claim', $signatureBase64)" in pair,
 'CLAIM_CREDENTIAL_VALIDATED': 'NavigatorCredentialScope::valid($credential)' in pair,
 'TESTS_PARALLEL_AND_ROLLBACK': 'TRUE_PARALLEL_CLAIM_ONE_WINNER' in test and 'ROLLBACK_NO_ORPHAN_DEVICE' in test,
 'FEATURE_FLAG_OFF': "config('fleetops.navigator.pairing_v2_enabled', false) === true" in controller,
}
for name,okay in checks.items():
 print('R75_STATIC_'+name+'='+('PASS' if okay else 'FAIL'))
assert all(checks.values())
print('R75_STATIC_SOURCE_GATES=11/11')
