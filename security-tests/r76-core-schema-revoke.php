<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
use Fleetbase\FleetOps\Support\NavigatorPairingV2Service;
function check(bool $okay, string $label): void {
    if (!$okay) throw new RuntimeException($label);
    $GLOBALS['gateCount']=($GLOBALS['gateCount'] ?? 0)+1; echo 'R76_MYSQL_'.$label.'=PASS'.PHP_EOL;
}
$cfg = ['driver'=>'mysql','host'=>'gst-r76-mysql','port'=>3306,'database'=>'synthetic_r76','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>''];
$cap = new Capsule();
$cap->addConnection($cfg,'mysql');
$cap->getDatabaseManager()->setDefaultConnection('mysql');

$cap->getContainer()->get('config')->set('database.default','mysql');
$cap->setAsGlobal();$cap->bootEloquent();
$container=$cap->getContainer();
$container->instance('db',$cap->getDatabaseManager());
$container->instance('db.schema',$cap->schema());
Facade::setFacadeApplication($container);
\Illuminate\Container\Container::setInstance($container);
$container->get('config')->set('api.cache.enabled',false);
$container->get('config')->set('fleetbase.connection.db','mysql');
$container->get('config')->set('activitylog.enabled',false);
$cfgRepo=new \Illuminate\Config\Repository($container->get('config')->toArray());
$container->instance('config',$cfgRepo);
$container->instance(\Illuminate\Contracts\Config\Repository::class,$cfgRepo);

DB::connection('mysql')->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
$schema=$cap->schema('mysql');
foreach(['fleetops_navigator_pending_pairings','fleetops_navigator_link_tokens',
    'personal_access_tokens','api_credentials','users','companies'] as $table) {
    $schema->dropIfExists($table);
}
$coreUsers=require '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094301_create_users_table.php';
$coreCompanies=require '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094305_create_companies_table.php';
$coreCred=require '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094311_create_api_credentials_table.php';
$sanctum=require '/fleetbase/api/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php';
$sanctumUuid=require '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094311_fix_personal_access_tokens.php';
$coreScope=require '/fleetbase/api/vendor/fleetbase/core-api/migrations/2026_11_11_000002_add_navigator_credential_scope.php';
$coreCompanies->up();
$coreUsers->up();
$coreCred->up();
$sanctum->up();
$container->get('config')->set('fleetbase.connection.sandbox', 'sandbox');
$sanctumUuid->up();
$coreScope->up();
check($schema->hasColumn('api_credentials','purpose')
    && $schema->hasColumn('api_credentials','issuer_user_uuid')
    && $schema->hasColumn('personal_access_tokens','navigator_credential_uuid'),
    'REAL_CORE_SCOPE_MIGRATION_UP');
check($schema->hasColumn('users','email') && $schema->hasColumn('api_credentials','browser_origins'),
    'REAL_CORE_BASE_SCHEMA_COLUMNS');
$m1=require '/lab/fork/server/migrations/2026_11_11_000001_create_fleetops_navigator_link_tokens_table.php';
$m2=require '/lab/fork/server/migrations/2026_11_12_000001_create_fleetops_navigator_pending_pairings_table.php';
$m1->up();$m2->up();
check($schema->hasTable('fleetops_navigator_pending_pairings'),'REAL_MIGRATION_UP');
DB::table('companies')->insert([['uuid'=>'companyA','name'=>'A','public_id'=>'A'],['uuid'=>'companyB','name'=>'B','public_id'=>'B']]);
DB::table('users')->insert([['uuid'=>'adminA','company_uuid'=>'companyA','type'=>'admin','status'=>'active','username'=>'adminA','name'=>'A'],['uuid'=>'adminB','company_uuid'=>'companyB','type'=>'admin','status'=>'active','username'=>'adminB','name'=>'B']]);
require '/lab/fork/server/src/Support/NavigatorLinkTokenService.php';
require '/lab/fork/server/src/Support/NavigatorPairingV2Service.php';
$issuer=User::with('company')->where('uuid','adminB')->firstOrFail();
$otherAdmin=User::with('company')->where('uuid','adminA')->firstOrFail();
$link=new NavigatorLinkTokenService();
$pending=new NavigatorPairingV2Service();
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
$pem=openssl_pkey_get_details($key)['key'];
check(NavigatorPairingV2Service::validatedPublicKey($pem)!==null,'P256_PUBLIC_KEY_VALID');
check(NavigatorPairingV2Service::validatedPublicKey('not-a-key')===null,'INVALID_PUBLIC_KEY_REFUSED');
$qr=$link->issue($issuer);
$started=$link->redeemWith($qr,fn(User $u)=>$pending->begin($u,$pem));
$session=$started['pairingSessionId'];
$confirmationCode = substr($started['publicKeyFingerprint'], 0, 12);
check(strlen($session)===64 && !array_key_exists('credential',$started),'QR_ONLY_PENDING_NO_CREDENTIAL');
check($link->redeem($qr)===null,'QR_REPLAY_REFUSED');
$before=$pending->status($session);
check($before!==null && !$before['approved'] && !$before['verified'] && $before['claimChallenge']===null,'PENDING_STATUS_NO_CREDENTIAL');
check(!$pending->approve($issuer,$session,$confirmationCode),'NO_APPROVAL_BEFORE_PROOF');
check($pending->pendingForIssuer($issuer)===[],'UNVERIFIED_NOT_IN_ADMIN_LIST');
check($pending->claim($session,base64_encode('wrong'))===null,'NO_CLAIM_BEFORE_PROOF_APPROVAL');
$row=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$session)->first();
$proof=NavigatorPairingV2Service::message('proof',$row);
openssl_sign($proof,$proofSignature,$key,OPENSSL_ALGO_SHA256);
check(!$pending->prove($session,base64_encode('wrong')),'INVALID_SIGNATURE_REJECTED');
check($pending->prove($session,base64_encode($proofSignature)),'VALID_DEVICE_SIGNATURE_ACCEPTED');
check(!$pending->prove($session,base64_encode($proofSignature)),'PROOF_REPLAY_REFUSED');
$pendingList=$pending->pendingForIssuer($issuer);
check(count($pendingList)===1 && $pendingList[0]['pairingSessionId']===$session
    && $pendingList[0]['deviceConfirmationCode']===$confirmationCode
    && !array_key_exists('claimChallenge',$pendingList[0]),'ISSUER_LIST_ONLY_VERIFIED_NO_SECRET');
check($pending->pendingForIssuer($otherAdmin)===[],'CROSS_TENANT_PENDING_LIST_EMPTY');
check(!$pending->approve($issuer,$session,str_repeat('0',12)),'WRONG_DEVICE_CONFIRMATION_REFUSED');
check(!$pending->approve($otherAdmin,$session,$confirmationCode),'CROSS_TENANT_ADMIN_REJECTED');
check($pending->claim($session,base64_encode('wrong'))===null,'NO_CLAIM_BEFORE_ADMIN_APPROVAL');
check($pending->approve($issuer,$session,$confirmationCode),'EXACT_ISSUER_APPROVED');
check(!$pending->approve($issuer,$session,$confirmationCode),'APPROVAL_REPLAY_REFUSED');
check($pending->pendingForIssuer($issuer)===[],'APPROVED_SESSION_REMOVED_FROM_PENDING_LIST');
$approved=$pending->status($session);
check($approved!==null && $approved['approved'] && strlen($approved['claimChallenge'])===64 && !array_key_exists('credential',$approved),'APPROVED_STATUS_NO_CREDENTIAL');
check($pending->claim($session,base64_encode('wrong'))===null,'WRONG_FINAL_SIGNATURE_REJECTED');
$finalRow=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$session)->first();
$claimMessage=NavigatorPairingV2Service::message('claim',$finalRow);
$otherKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
openssl_sign($claimMessage,$otherSignature,$otherKey,OPENSSL_ALGO_SHA256);
check($pending->claim($session,base64_encode($otherSignature))===null,'OTHER_DEVICE_FINAL_KEY_REJECTED');
check(DB::table('fleetops_navigator_pending_pairings')->where('session_id',$session)->whereNull('claimed_at')->exists(),'CREDENTIAL_NEVER_ISSUED_WITHOUT_VALID_FINAL_PROOF');
$expiredQr=$link->issue($issuer);
$expired=$link->redeemWith($expiredQr,fn(User $u)=>$pending->begin($u,$pem));
DB::table('fleetops_navigator_pending_pairings')->where('session_id',$expired['pairingSessionId'])->update(['expires_unix'=>time()-1]);
check($pending->status($expired['pairingSessionId'])===null,'EXPIRED_PENDING_SESSION_REFUSED');
require '/lab/fork/server/src/Support/NavigatorDatabaseGuard.php';
require '/lab/fork/server/src/Support/NavigatorCredentialService.php';
$container->instance('responsecache',new class {public function clear():void{}});
$container->instance('cache',new class {public function tags(array $tags): self{return $this;} public function flush():bool{return true;} public function forget(string $key):bool{return true;}});
$container->instance('hash',new \Illuminate\Hashing\BcryptHasher());
$container->instance('log',new \Illuminate\Log\Logger(new \Psr\Log\NullLogger()));
$events=new \Illuminate\Events\Dispatcher($container);
\Illuminate\Database\Eloquent\Model::setEventDispatcher($events);
\Fleetbase\Models\ApiCredential::observe(\Fleetbase\Observers\ApiCredentialObserver::class);
$container->instance(\Fleetbase\FleetOps\Support\NavigatorCredentialService::class, new \Fleetbase\FleetOps\Support\NavigatorCredentialService());
$qrFinal=$link->issue($issuer);
$opened=$link->redeemWith($qrFinal,fn(User $u)=>$pending->begin($u,$pem));
$finalSession=$opened['pairingSessionId'];
$proofRow=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$finalSession)->first();
openssl_sign(NavigatorPairingV2Service::message('proof',$proofRow),$signatureOne,$key,OPENSSL_ALGO_SHA256);
check($pending->prove($finalSession,base64_encode($signatureOne)),'FINAL_SESSION_PROOF');
check($pending->approve($issuer,$finalSession,substr($opened['publicKeyFingerprint'],0,12)),'FINAL_SESSION_ISSUER_APPROVAL');
$claimRow=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$finalSession)->first();
openssl_sign(NavigatorPairingV2Service::message('claim',$claimRow),$signatureTwo,$key,OPENSSL_ALGO_SHA256);
$issued=$pending->claim($finalSession,base64_encode($signatureTwo));
check($issued instanceof \Fleetbase\Models\ApiCredential && is_string($issued->key) && strlen($issued->key)>16,'ACTUAL_SCOPED_CREDENTIAL_ISSUED');
check($issued->company_uuid==='companyB' && $issued->purpose==='navigator','CREDENTIAL_TENANT_B_SCOPED');
check($pending->claim($finalSession,base64_encode($signatureTwo))===null,'FINAL_CLAIM_REPLAY_REFUSED');
check(DB::table('api_credentials')->count()===1,'ONE_CREDENTIAL_ROW');
$qrAbort=$link->issue($issuer);
$openedAbort=$link->redeemWith($qrAbort,fn(User $u)=>$pending->begin($u,$pem));
$abortSession=$openedAbort['pairingSessionId'];
$proofAbort=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$abortSession)->first();
openssl_sign(NavigatorPairingV2Service::message('proof',$proofAbort),$proofAbortSig,$key,OPENSSL_ALGO_SHA256);
check($pending->prove($abortSession,base64_encode($proofAbortSig)),'ROLLBACK_PAIR_PROOF_VALID');
check($pending->approve($issuer,$abortSession,substr($openedAbort['publicKeyFingerprint'],0,12)),'ROLLBACK_PAIR_APPROVED');
$claimAbort=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$abortSession)->first();
openssl_sign(NavigatorPairingV2Service::message('claim',$claimAbort),$claimAbortSig,$key,OPENSSL_ALGO_SHA256);
$container->instance(\Fleetbase\FleetOps\Support\NavigatorCredentialService::class,
    new class extends \Fleetbase\FleetOps\Support\NavigatorCredentialService {
        public function issue(User $issuer): \Fleetbase\Models\ApiCredential {
            parent::issue($issuer);
            throw new \RuntimeException('synthetic_rollback_request');
        }
    });
$caught=false;
try { $pending->claim($abortSession,base64_encode($claimAbortSig)); }
catch (\RuntimeException $e) { $caught=$e->getMessage()==='synthetic_rollback_request'; }
check($caught,'ROLLBACK_EXCEPTION_PROPAGATED');
echo 'R75_DEFAULT_DB='.DB::getDefaultConnection().PHP_EOL;
echo 'R75_USER_DB='.(new User())->getConnectionName().PHP_EOL;
echo 'R75_CREDENTIAL_DB='.(new \Fleetbase\Models\ApiCredential())->getConnectionName().PHP_EOL;
echo 'R75_CONNECTION_SHARED='.(DB::connection()===(new \Fleetbase\Models\ApiCredential())->getConnection()?'YES':'NO').PHP_EOL;
echo 'R75_ROWS_AFTER_ABORT='.DB::table('api_credentials')->count().PHP_EOL;
check(DB::table('api_credentials')->count()===1,'ROLLBACK_NO_ORPHAN_CREDENTIAL');
check(User::query()->where('type','navigator-device')->count()===1,'ROLLBACK_NO_ORPHAN_DEVICE');
check(DB::table('fleetops_navigator_pending_pairings')->where('session_id',$abortSession)->whereNull('claimed_at')->exists(),'ROLLBACK_SESSION_RETRYABLE');
$container->instance(\Fleetbase\FleetOps\Support\NavigatorCredentialService::class,
    new \Fleetbase\FleetOps\Support\NavigatorCredentialService());
$retryIssued=$pending->claim($abortSession,base64_encode($claimAbortSig));
check($retryIssued instanceof \Fleetbase\Models\ApiCredential,'RETRY_AFTER_ROLLBACK_SUCCEEDS');
check(DB::table('api_credentials')->count()===2,'RETRY_ISSUES_EXACTLY_ONE_MORE');
check($pending->claim($abortSession,base64_encode($claimAbortSig))===null,'RETRY_REPLAY_REFUSED');

$qrRace=$link->issue($issuer);
$raceSessionInfo=$link->redeemWith($qrRace,fn(User $u)=>$pending->begin($u,$pem));
$raceSession=$raceSessionInfo['pairingSessionId'];
$raceProofRow=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$raceSession)->first();
openssl_sign(NavigatorPairingV2Service::message('proof',$raceProofRow),$raceProofSig,$key,OPENSSL_ALGO_SHA256);
check($pending->prove($raceSession,base64_encode($raceProofSig)),'RACE_PAIR_PROOF');
check($pending->approve($issuer,$raceSession,substr($raceSessionInfo['publicKeyFingerprint'],0,12)),'RACE_PAIR_APPROVED');
$raceClaimRow=DB::table('fleetops_navigator_pending_pairings')->where('session_id',$raceSession)->first();
openssl_sign(NavigatorPairingV2Service::message('claim',$raceClaimRow),$raceClaimSig,$key,OPENSSL_ALGO_SHA256);
$parallel=[];
for($i=0;$i<2;$i++){
    $pid=pcntl_fork();
    if($pid===-1)throw new \RuntimeException('race_fork_failed');
    if($pid===0){
        try{
            $cap->getDatabaseManager()->purge('mysql');
            DB::connection('mysql')->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
            $container->instance(\Fleetbase\FleetOps\Support\NavigatorCredentialService::class,
                new class extends \Fleetbase\FleetOps\Support\NavigatorCredentialService {
                    public function issue(User $issuer): \Fleetbase\Models\ApiCredential {
                        usleep(230000);
                        return parent::issue($issuer);
                    }
                });
            $candidate=(new NavigatorPairingV2Service())->claim($raceSession,base64_encode($raceClaimSig));
            exit($candidate instanceof \Fleetbase\Models\ApiCredential ? 11 : ($candidate===null ? 22 : 33));
        }catch(\Throwable $e){exit(44);}
    }
    $parallel[]=$pid;
}
$codes=[];
foreach($parallel as $child){
    pcntl_waitpid($child,$status);
    $codes[]=pcntl_wexitstatus($status);
}
sort($codes);
check($codes===[11,22],'TRUE_PARALLEL_CLAIM_ONE_WINNER');
check(DB::table('api_credentials')->count()===3,'PARALLEL_ONE_NEW_CREDENTIAL');
check(DB::table('fleetops_navigator_pending_pairings')->where('session_id',$raceSession)->whereNotNull('claimed_at')->exists(),'PARALLEL_SESSION_CONSUMED');
check($pending->claim($raceSession,base64_encode($raceClaimSig))===null,'PARALLEL_LATE_REPLAY_REJECTED');
// R76 revoke and migration down fail-closed on *actual* Core API tables.
$scoped=\Fleetbase\Models\ApiCredential::withoutGlobalScopes()->where('uuid',$issued->uuid)->firstOrFail();
check(\Fleetbase\Support\NavigatorCredentialScope::valid($scoped),'CORE_SCOPED_VALID_BEFORE_REVOKE');

$originalExpiry=$scoped->expires_at->toDateTimeString();
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['expires_at'=>now()->subMinutes(2)]);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_EXPIRED_CREDENTIAL_REJECTED');
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['expires_at'=>now()->addDays(8)]);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_OVERLONG_CREDENTIAL_REJECTED');
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['expires_at'=>$originalExpiry]);
DB::table('users')->where('uuid',$scoped->user_uuid)->update(['status'=>'inactive']);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_DISABLED_DEVICE_REJECTED');
DB::table('users')->where('uuid',$scoped->user_uuid)->update(['status'=>'active']);
DB::table('users')->where('uuid',$scoped->issuer_user_uuid)->update(['status'=>'inactive']);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_DISABLED_ISSUER_REJECTED');
DB::table('users')->where('uuid',$scoped->issuer_user_uuid)->update(['status'=>'active']);
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['company_uuid'=>'companyA']);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_CROSS_TENANT_CREDENTIAL_REJECTED');
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['company_uuid'=>'companyB']);
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['purpose'=>null]);
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_SCOPE_STRIPPING_REJECTED');
DB::table('api_credentials')->where('uuid',$scoped->uuid)->update(['purpose'=>'navigator']);
check(\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh()),'CORE_RESTORED_CREDENTIAL_VALID');

$driverId='driverB';
DB::table('users')->insert([
    'uuid'=>$driverId,'company_uuid'=>'companyB','type'=>'driver',
    'status'=>'active','username'=>'driver-b','name'=>'synthetic-driver',
]);
DB::table('personal_access_tokens')->insert([
    'tokenable_type'=>User::class,'tokenable_id'=>$driverId,
    'name'=>'synthetic-linked-driver-token','token'=>str_repeat('e',64),
    'abilities'=>'["driver"]','navigator_credential_uuid'=>null,
    'expires_at'=>null,
]);
$request=\Illuminate\Http\Request::create('/synthetic/driver/login', 'POST');
$request->attributes->set('navigator_credential',$scoped);
$container->instance('request',$request);
$driverToken=\Laravel\Sanctum\PersonalAccessToken::query()
    ->where('token',str_repeat('e',64))->firstOrFail();
\Fleetbase\Support\NavigatorCredentialScope::bindDriverToken($driverToken);
check(DB::table('personal_access_tokens')->where('token',str_repeat('e',64))
    ->where('navigator_credential_uuid',$scoped->uuid)->exists(),
    'CORE_TOKEN_BOUND_TO_SCOPED_PARENT');
$linkedExpiry=DB::table('personal_access_tokens')->where('token',str_repeat('e',64))->value('expires_at');
check($linkedExpiry !== null && abs(strtotime((string)$linkedExpiry)-$scoped->expires_at->timestamp)<=1,
    'CORE_DRIVER_TOKEN_INHERITS_PARENT_EXPIRY');
check(\Fleetbase\Support\NavigatorCredentialScope::parent($driverToken->fresh())?->uuid===$scoped->uuid,
    'CORE_LINKED_TOKEN_PARENT_RESOLVES');
DB::table('users')->insert([
    'uuid'=>'driverA','company_uuid'=>'companyA','type'=>'driver',
    'status'=>'active','username'=>'driver-a','name'=>'synthetic-other-driver',
]);
DB::table('personal_access_tokens')->insert([
    'tokenable_type'=>User::class,'tokenable_id'=>'driverA',
    'name'=>'synthetic-cross-tenant-token','token'=>str_repeat('c',64),
    'abilities'=>'["driver"]',
]);
$otherDriverToken=\Laravel\Sanctum\PersonalAccessToken::query()
    ->where('token',str_repeat('c',64))->firstOrFail();
$crossTokenDenied=false;
try{\Fleetbase\Support\NavigatorCredentialScope::bindDriverToken($otherDriverToken);}
catch(\RuntimeException $e){$crossTokenDenied=true;}
check($crossTokenDenied,'CORE_OTHER_TENANT_DRIVER_BIND_DENIED');
check(!DB::table('personal_access_tokens')->where('token',str_repeat('c',64))->exists(),
    'CORE_REJECTED_DRIVER_TOKEN_DELETED');
check(DB::table('personal_access_tokens')->where('navigator_credential_uuid',$scoped->uuid)->count()===1,
    'CORE_LINKED_DRIVER_SESSION_EXISTS');
$shouldBlockScopeDown=false;
try{$coreScope->down();}catch(\RuntimeException $e){$shouldBlockScopeDown=true;}
check($shouldBlockScopeDown,'CORE_DOWN_DENIED_WHILE_SCOPED_KEY_EXISTS');
$service=app(\Fleetbase\FleetOps\Support\NavigatorCredentialService::class);
$crossTenantBlocked=false;
try{$service->revoke($otherAdmin,$scoped->uuid);}catch(\RuntimeException $e){$crossTenantBlocked=true;}
check($crossTenantBlocked && \Fleetbase\Models\ApiCredential::withoutGlobalScopes()->where('uuid',$scoped->uuid)->whereNull('deleted_at')->exists(),
    'CROSS_TENANT_REVOKE_DENIED');
$service->revoke($issuer,$scoped->uuid);
check(\Fleetbase\Models\ApiCredential::withoutGlobalScopes()->where('uuid',$scoped->uuid)->whereNotNull('deleted_at')->exists(),
    'REVOKE_SOFT_DELETES_SCOPED_CREDENTIAL');
check(DB::table('personal_access_tokens')->where('navigator_credential_uuid',$scoped->uuid)->count()===0,
    'REVOKE_DELETES_LINKED_DRIVER_SESSION');
check(!\Fleetbase\Support\NavigatorCredentialScope::valid($scoped->fresh() ?? $scoped),
    'REVOKED_CREDENTIAL_UNUSABLE');
$secondRevokeDenied=false;
try{$service->revoke($issuer,$scoped->uuid);}catch(\RuntimeException $e){$secondRevokeDenied=true;}
check($secondRevokeDenied,'SECOND_REVOKE_FAILS_CLOSED');
$allKeys=DB::table('api_credentials')->where('purpose','navigator')->pluck('uuid')->all();
check(count($allKeys)===3,'CORE_MIGRATION_PRE_DOWN_HAS_THREE_KEYS');
$m2->down();$m1->down();
$downBlockedWithSoftDeleted=false;
try{$coreScope->down();}catch(\RuntimeException $e){$downBlockedWithSoftDeleted=true;}
check($downBlockedWithSoftDeleted,'CORE_SCOPE_DOWN_BLOCKS_SOFT_DELETED_SCOPED_KEYS');
DB::table('api_credentials')->where('purpose','navigator')->delete();
DB::table('personal_access_tokens')->whereNotNull('navigator_credential_uuid')->delete();
DB::table('personal_access_tokens')->insert([
    'tokenable_type'=>User::class,'tokenable_id'=>'driverB',
    'name'=>'synthetic-orphan-credential-check','token'=>str_repeat('f',64),
    'abilities'=>'[]','navigator_credential_uuid'=>'synthetic-orphan',
]);
$scopeDownMustFail=false;
try{$coreScope->down();}catch(\RuntimeException $e){$scopeDownMustFail=true;}
check($scopeDownMustFail,'CORE_SCOPE_DOWN_BLOCKS_ORPHAN_TOKEN_REFERENCE');
DB::table('personal_access_tokens')->where('navigator_credential_uuid','synthetic-orphan')->delete();
$coreScope->down();
check(!$schema->hasColumn('api_credentials','purpose')
    && !$schema->hasColumn('personal_access_tokens','navigator_credential_uuid'),
    'CORE_SCOPE_DOWN_AFTER_EXPLICIT_REMOVAL');
$sanctum->down();$coreCred->down();$coreUsers->down();$coreCompanies->down();
check(!$schema->hasTable('api_credentials') && !$schema->hasTable('users'),'CORE_BASE_MIGRATIONS_DOWN');

check(!$schema->hasTable('fleetops_navigator_pending_pairings'),'REAL_MIGRATION_DOWN');
echo 'R76_REAL_CORE_SCHEMA_AND_REVOKE_GATES='.$GLOBALS['gateCount'].'/'.$GLOBALS['gateCount'].PHP_EOL;
