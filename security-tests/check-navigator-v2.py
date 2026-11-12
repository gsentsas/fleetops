from pathlib import Path
import re
c=Path("server/src/Http/Controllers/Internal/v1/NavigatorController.php").read_text()
r=Path("server/src/routes.php").read_text()
service=Path("server/src/Support/NavigatorLinkTokenService.php").read_text()
pest=Path("server/tests/NavigatorControllerContractsTest.php").read_text()
pending=Path("server/src/Support/NavigatorPairingV2Service.php").read_text()
migration=Path("server/migrations/2026_11_12_000001_create_fleetops_navigator_pending_pairings_table.php").read_text()
tests={
  "legacy_GET_is_fail_closed": bool(re.search(r"function linkApp\(Request \$request\).*?return response\(\)->error\(.*?403\)",c,re.S)),
  "legacy_issuer_GET_is_fail_closed": bool(re.search(r"function getLinkAppUrl\(\).*?return response\(\)->error\(.*?403\)",c,re.S)),
  "protected_POST_issue": "post('pairings', 'NavigatorController@issuePairing')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:5,1'])" in r,
  "POST_body_redeem": "post('pairings/redeem', 'NavigatorController@redeemPairing')" in r and "$request->json('pairingSecret')" in c,
  "no_URL_token_generation": "http_build_query(['token' => $token])" not in c,
  "no_deeplink_key": "intent://configure?" not in c and "flbnavigator://configure?" not in c,
  "no_redirect_credential": "redirectAway($intentUrl)" not in c and "redirectAway($iosUrl)" not in c,
  "no_request_query_in_POST": "$request->query() !== []" in c,
  "POST_validates_JSON": "$request->isJson()" in c,
  "POST_invalid_secret_rejected": "ctype_xdigit($secret)" in c and "strlen($secret) !== 64" in c,
  "POST_response_no_store": "function redeemPairing" in c and "'Cache-Control', 'no-store'" in c,
  "issuer_auth_has_admin_gate": "'issuePairing' => 'admin'" in c,
  "legacy_GET_route_preserved_for_explicit_denial": "get('link-app', 'NavigatorController@linkApp')" in r,
  "raw_exception_not_sent": "return response()->error($exception" not in c,
  "feature_gate_default_off": "config('fleetops.navigator.pairing_v2_enabled', false) === true" in c,
  "feature_gate_issue_redeem_rotate": c.count("if (!$this->navigatorV2Enabled())") == 3,
  "ttl_5_minute_backend": "now()->addMinutes(5)" in service and "now()->addMinutes(30)" not in service,
  "ttl_response_300_sec": "public const LINK_TTL_MINUTES = 5;" in c and c.count("self::LINK_TTL_MINUTES * 60") == 2,
  "credential_inside_claim_transaction": "return DB::transaction(" in pending and "app(NavigatorCredentialService::class)->issue($issuer)" in pending and "'claimed_at' => now()->toDateTimeString()" in pending,
  "QR_creates_pending_not_credential": "->redeemWith(" in c and "->begin($issuer, $publicKey)" in c,
  "proof_requires_signature": "function prove(" in pending and "openssl_verify(" in pending and "OPENSSL_ALGO_SHA256" in pending,
  "admin_approval_exact_issuer": "$actor->uuid !== $row->issuer_user_uuid" in pending and "$actor->company_uuid !== $row->company_uuid" in pending,
  "claim_requires_both": "!$row->verified_at || !$row->approved_at" in pending,
  "mobile_final_claim_second_signature": "self::signatureMatches($row, 'claim', $signatureBase64)" in pending,
  "all_v2_routes_feature_off": c.count("!$this->navigatorV2Enabled()") >= 7,
  "new_migration_has_key_and_challenges": "fleetops_navigator_pending_pairings" in migration and "public_key_pem" in migration and "claim_nonce" in migration,
  "approve_route_is_protected": "post('pairings/approve', 'NavigatorController@approvePairing')->middleware(['fleetbase.protected', \\Fleetbase\\FleetOps\\Http\\Middleware\\EnsureNavigatorAdminRequest::class, 'throttle:10,1'])" in r,
  "pest_v2_contract_cases_written": pest.count("test('navigator v2") >= 10,

}
for k,v in tests.items():
 print("R71_"+k+"="+("PASS" if v else "FAIL"))
assert all(tests.values())
print(f"R71_STATIC_GATES={len(tests)}/{len(tests)}")
