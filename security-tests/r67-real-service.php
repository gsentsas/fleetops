<?php
declare(strict_types=1);
namespace { require '/fleetbase/api/vendor/autoload.php'; }
namespace Fleetbase\Models {
 class Company extends \Illuminate\Database\Eloquent\Model {
   protected $table='companies'; public $timestamps=false; public $incrementing=false; protected $primaryKey='uuid'; protected $keyType='string'; protected $guarded=[];
 }
 class User extends \Illuminate\Database\Eloquent\Model {
   protected $table='users'; public $timestamps=false; public $incrementing=false; protected $primaryKey='uuid'; protected $keyType='string'; protected $guarded=[];
   public function company() {return $this->belongsTo(Company::class,'company_uuid','uuid');}
 }
}
namespace {
 // Exact service from modified, isolated fork (never from image vendor).
 require '/lab/fork/server/src/Support/NavigatorLinkTokenService.php';
 use Illuminate\Database\Capsule\Manager as Capsule;
 use Illuminate\Support\Facades\DB;
 use Fleetbase\Models\Company;
 use Fleetbase\Models\User;
 use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
 function gate(bool $pass,string $name):void {if(!$pass)throw new RuntimeException($name); echo 'R67_REAL_SERVICE_'.$name.'=PASS'.PHP_EOL;}
 $capsule=new Capsule();
 $capsule->addConnection(['driver'=>'mysql','host'=>'gst-r67-mysql','port'=>3306,'database'=>'synthetic_r67','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']);
 $capsule->setAsGlobal(); $capsule->bootEloquent();
 \Illuminate\Support\Facades\Facade::setFacadeApplication($capsule->getContainer());
 $capsule->getContainer()->instance('db', $capsule->getDatabaseManager());
 $schema=$capsule->schema();
 foreach (['fleetops_navigator_link_tokens','users','companies'] as $table) {$schema->dropIfExists($table);}
 $schema->create('companies',function($t){$t->string('uuid',36)->primary();});
 $schema->create('users',function($t){$t->string('uuid',36)->primary();$t->string('company_uuid',36);$t->string('type');$t->string('status');});
 $schema->create('fleetops_navigator_link_tokens',function($t){$t->bigIncrements('id');$t->string('token_hash',64)->unique();$t->string('company_uuid',36);$t->string('issuer_user_uuid',36);$t->dateTime('expires_at');$t->dateTime('consumed_at')->nullable();$t->dateTime('created_at');$t->dateTime('updated_at');});
 Company::create(['uuid'=>'company-A']);Company::create(['uuid'=>'company-B']);
 User::create(['uuid'=>'admin-A','company_uuid'=>'company-A','type'=>'admin','status'=>'active']);
 User::create(['uuid'=>'admin-B','company_uuid'=>'company-B','type'=>'admin','status'=>'active']);
 $issuer=User::with('company')->where('uuid','admin-B')->firstOrFail();
 $service=new NavigatorLinkTokenService();
 $token=$service->issue($issuer);
 $row=DB::table('fleetops_navigator_link_tokens')->first();
 gate(is_string($token)&&strlen($token)===64&&$row->token_hash===hash('sha256',$token),'hash_at_rest');
 gate($row->company_uuid==='company-B'&&$row->issuer_user_uuid==='admin-B','issuer_tenant_bound');
 $ttl=strtotime($row->expires_at)-time();
 gate($ttl>=295&&$ttl<=305,'five_minute_ttl');
 $res=$service->redeemWith($token,function(User $u){return $u->company_uuid;});
 gate($res==='company-B','first_redeem_correct_company');
 gate($service->redeem($token)===null,'replay_rejected');
 $token2=$service->issue($issuer);
 try {$service->redeemWith($token2,function(User $u){throw new RuntimeException('synthetic_abort');});}catch(RuntimeException $e){}
 gate(DB::table('fleetops_navigator_link_tokens')->where('token_hash',hash('sha256',$token2))->whereNull('consumed_at')->exists(),'rollback_preserves_token');
 gate($service->redeemWith($token2,function(User $u){return 'retry';})==='retry','retry_after_rollback');
 $token3=$service->issue($issuer);
 DB::table('fleetops_navigator_link_tokens')->where('token_hash',hash('sha256',$token3))->update(['expires_at'=>now()->subMinute()->toDateTimeString()]);
 gate($service->redeem($token3)===null,'expired_rejected');
 $token4=$service->issue($issuer);
 User::where('uuid','admin-B')->update(['status'=>'inactive']);
 gate($service->redeem($token4)===null,'inactive_issuer_rejected');
 gate($service->redeem(str_repeat('0',64))===null,'forged_rejected');
 echo 'R67_REAL_NAVIGATOR_LINK_TOKEN_SERVICE=10/10'.PHP_EOL;
}
