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
    $GLOBALS['gateCount']=($GLOBALS['gateCount'] ?? 0)+1; echo 'R75_MYSQL_'.$label.'=PASS'.PHP_EOL;
}
$cfg = ['driver'=>'mysql','host'=>'gst-r75-mysql','port'=>3306,'database'=>'synthetic_r75','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>''];
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
foreach(['api_credentials','fleetops_navigator_pending_pairings','fleetops_navigator_link_tokens','users','companies'] as $table)$schema->dropIfExists($table);
$schema->create('companies',function($t){$t->string('uuid',36)->primary();$t->string('name')->nullable();$t->string('public_id')->nullable();$t->softDeletes();$t->timestamps();});
$schema->create('api_credentials',function($t){
$t->string('uuid',36)->primary();$t->string('user_uuid',36);
$t->string('company_uuid',36);$t->string('issuer_user_uuid',36)->nullable();
$t->string('purpose')->nullable();$t->string('name')->nullable();
$t->string('key')->nullable();$t->text('secret')->nullable();
$t->dateTime('expires_at')->nullable();$t->boolean('test_mode')->default(false);
$t->string('public_id')->nullable();$t->softDeletes();$t->timestamps();
});
$schema->create('users',function($t){$t->string('uuid',36)->primary();$t->string('company_uuid',36);$t->string('type');$t->string('status');$t->string('username')->nullable();$t->string('name')->nullable();$t->softDeletes();$t->timestamps();});
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
$m2->down();$m1->down();
check(!$schema->hasTable('fleetops_navigator_pending_pairings'),'REAL_MIGRATION_DOWN');
echo 'R75_REAL_NAVIGATOR_CLAIM_GATES='.$GLOBALS['gateCount'].'/'.$GLOBALS['gateCount'].PHP_EOL;
