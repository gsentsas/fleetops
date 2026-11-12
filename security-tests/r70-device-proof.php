<?php
declare(strict_types=1);
// Synthetic cryptographic contract ONLY: generated software key (not Keystore).
function gate(bool $ok,string $name):void {
    if (!$ok) throw new RuntimeException($name);
    echo "R70_CRYPTO_".$name."=PASS".PHP_EOL;
}
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
if(!$key)throw new RuntimeException('EC P256 unavailable');
$public=openssl_pkey_get_details($key)['key'];
$challenge=bin2hex(random_bytes(32));
$session=bin2hex(random_bytes(16));
$company='company-B';
$device=bin2hex(random_bytes(16));
$expires=time()+120;
function payload(string $session,string $company,string $device,string $challenge,int $expires):string {
    return implode("\n",['navigator-v2-device-proof-v1',$session,$company,$device,$challenge,(string)$expires]);
}
$message=payload($session,$company,$device,$challenge,$expires);
openssl_sign($message,$signature,$key,OPENSSL_ALGO_SHA256);
gate(openssl_verify($message,$signature,$public,OPENSSL_ALGO_SHA256)===1,'ECDSA_P256_SIGNATURE_VALID');
gate(openssl_verify(payload($session,'company-A',$device,$challenge,$expires),$signature,$public,OPENSSL_ALGO_SHA256)!==1,'CROSS_TENANT_REJECTED');
gate(openssl_verify(payload($session,$company,$device,bin2hex(random_bytes(32)),$expires),$signature,$public,OPENSSL_ALGO_SHA256)!==1,'TAMPERED_CHALLENGE_REJECTED');
$other=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
$otherKey=openssl_pkey_get_details($other)['key'];
gate(openssl_verify($message,$signature,$otherKey,OPENSSL_ALGO_SHA256)!==1,'OTHER_DEVICE_KEY_REJECTED');
$used=[];
$verifyOnce=static function(string $msg,string $sig,string $pub,int $exp,string $sid) use (&$used):bool {
    if(time()>=$exp||isset($used[$sid])||openssl_verify($msg,$sig,$pub,OPENSSL_ALGO_SHA256)!==1)return false;
    $used[$sid]=true;
    return true;
};
gate($verifyOnce($message,$signature,$public,$expires,$session),'ONE_TIME_CHALLENGE_ACCEPTED');
gate(!$verifyOnce($message,$signature,$public,$expires,$session),'CHALLENGE_REPLAY_REJECTED');
gate(!$verifyOnce(payload('expired',$company,$device,$challenge,time()-1),$signature,$public,time()-1,'expired'),'EXPIRED_CHALLENGE_REJECTED');
echo 'R70_SOFTWARE_CRYPTO_CONTRACT=7/7'.PHP_EOL;
