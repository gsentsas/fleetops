<?php
declare(strict_types=1);
// Real wire HTTP; credentials, bodies, cookies and signatures stay in memory.
$fixtures = json_decode(file_get_contents('/tmp/r78-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$results = [];
function request(string $method, string $path, ?array $body = null, ?string $token = null, string $origin = 'https://console.lab.test', ?string $cookie = null): array
{
    static $round = 0;
    $target = !empty($GLOBALS['alternateTargets'])
        ? ((++$round % 2) ? getenv('R78_SECOND_API') : getenv('R78_FIRST_API')) : '127.0.0.1';
    $ch = curl_init('http://' . $target . ':8000' . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Host: api.lab.test'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($origin !== '') $headers[] = 'Origin: ' . $origin;
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    $responseHeaders = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $responseHeaders[strtolower(trim($k))][] = trim($v); }
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_errno($ch);
    curl_close($ch);
    return ['status' => $info['http_code'], 'curl_errno' => $error, 'total' => $info['total_time'],
        'json' => is_string($raw) ? json_decode($raw, true) : null, 'headers' => $responseHeaders];
}
function check(string $name, bool $ok, ?array $response = null): void
{
    global $results;
    $results[] = ['name' => $name, 'pass' => $ok, 'status' => $response['status'] ?? null,
        'curl_errno' => $response['curl_errno'] ?? null, 'seconds' => $response['total'] ?? null];
    file_put_contents('/tmp/r78-result.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . ($response ? ' HTTP=' . $response['status'] : '') . "\n";
}
function login(string $label): ?string
{
    global $fixtures;
    $r = request('POST', '/int/v1/auth/login', ['identity' => $fixtures[$label]['email'], 'password' => $fixtures[$label]['password']]);
    check('LOGIN_' . $label, $r['status'] === 200 && is_string($r['json']['token'] ?? null), $r);
    return $r['json']['token'] ?? null;
}
$bad = request('POST', '/int/v1/auth/login', ['identity' => 'admina@example.test', 'password' => 'wrong-synthetic-password']);
check('BAD_PASSWORD', $bad['status'] === 401, $bad);
$a = login('adminA'); $b = login('adminB');
if (!$a || !$b) exit(3);
if (!str_starts_with(getenv('DB_HOST') ?: '', 'gst-r78-')) throw new RuntimeException('Disposable DB required');
$db = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tokenHash = static fn (string $token): string => hash('sha256', explode('|', $token, 2)[1]);
$stmt = $db->prepare('SELECT expires_at FROM personal_access_tokens WHERE token = ?');
$stmt->execute([$tokenHash($a)]);
$expiry = strtotime((string) $stmt->fetchColumn());
check('ADMIN_TOKEN_BOUNDED_TTL', $expiry > time() && $expiry <= time() + 7200);
$base = '/int/v1/fleet-ops/navigator';
$r = request('POST', $base . '/pairings', [], $a);
check('ADMIN_ISSUE', $r['status'] === 200 && preg_match('/^[a-f0-9]{64}$/D', $r['json']['pairingSecret'] ?? '') === 1, $r);
check('QR_PRIVACY_HEADERS', str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'no-store')
    && in_array('no-referrer', $r['headers']['referrer-policy'] ?? [], true));
$cookies = $r['headers']['set-cookie'] ?? [];
$cookie = implode('; ', array_map(static fn ($value) => explode(';', $value)[0], $cookies));
check('SESSION_COOKIE_FLAGS', $cookies !== [] && count(array_filter($cookies, static fn ($c) => str_contains(strtolower($c), 'httponly') && str_contains(strtolower($c), 'secure') && str_contains(strtolower($c), 'samesite=lax'))) === count($cookies));
$cookieOnly = request('POST', $base . '/pairings', [], null, 'https://untrusted.synthetic.invalid', $cookie);
check('COOKIE_ONLY_NO_AUTHORITY', $cookieOnly['status'] === 401 || $cookieOnly['status'] === 403, $cookieOnly);
$cross = request('POST', $base . '/pairings/admin-pending', [], $a, 'https://untrusted.synthetic.invalid');
check('UNTRUSTED_ORIGIN_WITH_BEARER', $cross['status'] === 403, $cross);
$missing = request('POST', $base . '/pairings/admin-pending', [], $a, '');
check('NON_BROWSER_BEARER', $missing['status'] === 200, $missing);
// Keep later scenarios independent of QR creation failure.
if ($r['status'] !== 200 || !isset($r['json']['pairingSecret'])) exit(4);
$secret = $r['json']['pairingSecret'];
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pem = openssl_pkey_get_details($key)['key'];
$begin = request('POST', $base . '/pairings/redeem', ['pairingSecret' => $secret, 'publicKeyPem' => $pem]);
check('QR_TO_PENDING', $begin['status'] === 200 && isset($begin['json']['pairingSessionId']) && !isset($begin['json']['credential']), $begin);
if ($begin['status'] !== 200 || !isset($begin['json']['pairingSessionId'])) exit(5);
$pending = $begin['json']; $sid = $pending['pairingSessionId'];
$message = implode("\n", ['fleetbase.navigator.pairing.v2/proof', $sid, $pending['companyUuid'], $pending['publicKeyFingerprint'], $pending['challenge'], (string) $pending['challengeExpiresUnix']]);
openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
$proof = request('POST', $base . '/pairings/proof', ['pairingSessionId' => $sid, 'signature' => base64_encode($signature)]);
check('FIRST_DEVICE_PROOF', $proof['status'] === 200, $proof);
$list = request('POST', $base . '/pairings/admin-pending', [], $a);
check('ISSUER_PENDING_VISIBLE', $list['status'] === 200 && count($list['json']['pendingPairings'] ?? []) === 1, $list);
$listB = request('POST', $base . '/pairings/admin-pending', [], $b);
check('OTHER_TENANT_LIST_EMPTY', $listB['status'] === 200 && ($listB['json']['pendingPairings'] ?? null) === [], $listB);
$approval = ['pairingSessionId' => $sid, 'deviceConfirmationCode' => substr($pending['publicKeyFingerprint'], 0, 12)];
$wrong = request('POST', $base . '/pairings/approve', $approval, $b);
check('OTHER_TENANT_APPROVAL_DENIED', $wrong['status'] === 403, $wrong);
$approve = request('POST', $base . '/pairings/approve', $approval, $a);
check('EXACT_ISSUER_APPROVAL', $approve['status'] === 200, $approve);
$status = request('POST', $base . '/pairings/status', ['pairingSessionId' => $sid]);
check('SECOND_CHALLENGE_AVAILABLE', $status['status'] === 200 && isset($status['json']['claimChallenge']), $status);
if ($status['status'] === 200 && isset($status['json']['claimChallenge'])) {
    $message = implode("\n", ['fleetbase.navigator.pairing.v2/claim', $sid, $pending['companyUuid'], $pending['publicKeyFingerprint'], $status['json']['claimChallenge'], (string) $status['json']['claimExpiresUnix']]);
    openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
    $claimPayload = ['pairingSessionId' => $sid, 'signature' => base64_encode($signature)];
    $claim = request('POST', $base . '/pairings/claim', $claimPayload);
    check('FINAL_CREDENTIAL_HTTP', $claim['status'] === 200 && is_string($claim['json']['credential'] ?? null), $claim);
    $replay = request('POST', $base . '/pairings/claim', $claimPayload);
    check('CLAIM_REPLAY_DENIED', $replay['status'] === 403, $replay);
    if ($claim['status'] === 200 && isset($claim['json']['credential'])) {
        $credential = $claim['json']['credential'];
        $r = request('GET', '/v1/orders', null, $credential);
        check('PROVISIONING_KEY_CANNOT_READ_ORDERS', $r['status'] === 403, $r);
        $stmt = $db->prepare("SELECT uuid FROM api_credentials WHERE purpose = 'navigator' AND company_uuid = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$fixtures['adminA']['company']]);
        $uuid = $stmt->fetchColumn();
        $r = request('DELETE', $base . '/pairings/' . $uuid, [], $b);
        check('OTHER_TENANT_REVOKE_DENIED', $r['status'] === 403, $r);
        $r = request('DELETE', $base . '/pairings/' . $uuid, [], $a);
        check('ISSUER_REVOKE_HTTP', $r['status'] === 200, $r);
        $r = request('GET', '/v1/orders', null, $credential);
        check('REVOKED_CREDENTIAL_HTTP_DENIED', $r['status'] === 401, $r);
        $r = request('DELETE', $base . '/pairings/' . $uuid, [], $a);
        check('SECOND_REVOKE_DENIED', $r['status'] === 403, $r);
    }
}
$csrf = request('POST', $base . '/pairings/approve', $approval, null, 'https://untrusted.synthetic.invalid', $cookie);
check('COOKIE_CSRF_APPROVAL_DENIED', in_array($csrf['status'], [401, 403], true), $csrf);
$spoof = request('POST', $base . '/pairings/admin-pending', [], $a, 'https://console.lab.test.evil.invalid');
check('ORIGIN_SUFFIX_SPOOF_DENIED', $spoof['status'] === 403, $spoof);
$logout = request('POST', '/int/v1/auth/logout', [], $a);
check('ADMIN_LOGOUT_HTTP', $logout['status'] === 200, $logout);
$after = request('POST', $base . '/pairings/admin-pending', [], $a);
check('LOGOUT_REVOKES_BEARER', $after['status'] === 401, $after);
$expired = login('adminB');
if ($expired) {
    $stmt = $db->prepare('UPDATE personal_access_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token = ?');
    $stmt->execute([$tokenHash($expired)]);
    $r = request('POST', $base . '/pairings/admin-pending', [], $expired);
    check('EXPIRED_ADMIN_TOKEN_DENIED', $r['status'] === 401, $r);
    $r = request('POST', '/int/v1/auth/login', ['identity' => $fixtures['adminB']['email'], 'password' => 'wrong-synthetic-password', 'authToken' => $expired]);
    check('EXPIRED_TOKEN_CANNOT_REAUTHENTICATE', $r['status'] === 401, $r);
}
$stmt = $db->prepare('UPDATE users SET status = ? WHERE uuid = ?');
$stmt->execute(['inactive', $fixtures['adminA']['uuid']]);
$r = request('POST', '/int/v1/auth/login', ['identity' => $fixtures['adminA']['email'], 'password' => $fixtures['adminA']['password']]);
check('INACTIVE_ADMIN_LOGIN_DENIED', $r['status'] === 401 || $r['status'] === 403, $r);
// Flush ONLY this disposable, private Redis. Independent HTTP containers
// must share one limiter, including after a runtime/process change.
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'));
$redis->flushDB();
$GLOBALS['alternateTargets'] = true;
$codes = [];
for ($i = 0; $i < 12; $i++) {
    $response = request('POST', $base . '/pairings/redeem', ['pairingSecret' => str_repeat('0', 64), 'publicKeyPem' => 'invalid']);
    $codes[] = $response['status'];
}
check('REDIS_RATE_LIMIT_TWO_HTTP_CONTAINERS', $codes === array_merge(array_fill(0, 10, 403), [429, 429]));
echo 'R78_RATE_HTTP_CODES=' . implode(',', $codes) . "\n";
$auditValues = array_merge([$a, $b, $expired ?? null, $credential ?? null, $secret], array_column($fixtures, 'password'));
file_put_contents('/tmp/r78-sensitive.json', json_encode($auditValues, JSON_THROW_ON_ERROR));
chmod('/tmp/r78-sensitive.json', 0600);

$failed = count(array_filter($results, static fn ($row) => !$row['pass']));
echo 'R78_REAL_HTTP=' . (count($results) - $failed) . '/' . count($results) . "\n";
exit($failed ? 1 : 0);
