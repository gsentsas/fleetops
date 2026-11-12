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
 use Illuminate\Database\Capsule\Manager as Capsule;
 use Illuminate\Support\Facades\Facade;
 use Illuminate\Support\Facades\Schema;
 use Illuminate\Support\Facades\DB;
 use Fleetbase\Models\Company;
 use Fleetbase\Models\User;
 use Fleetbase\FleetOps\Support\NavigatorLinkTokenService;
 function verify(bool $yes,string $name):void {if(!$yes)throw new RuntimeException($name);echo "R68_".$name."=PASS\n";}
 $capsule=new Capsule();
 $capsule->addConnection(['driver'=>'mysql','host'=>'gst-r68-mysql','port'=>3306,'database'=>'synthetic_r68','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']);
 $capsule->setAsGlobal();$capsule->bootEloquent();
 $container=$capsule->getContainer();$container->instance('db',$capsule->getDatabaseManager());$container->instance('db.schema',$capsule->schema());
 Facade::setFacadeApplication($container);
 foreach (['fleetops_navigator_link_tokens','users','companies'] as $t){$capsule->schema()->dropIfExists($t);}
 $capsule->schema()->create('companies',function($t){$t->string('uuid',36)->primary();});
 $capsule->schema()->create('users',function($t){$t->string('uuid',36)->primary();$t->string('company_uuid',36);$t->string('type');$t->string('status');});
 $migration=require '/lab/fork/server/migrations/2026_11_11_000001_create_fleetops_navigator_link_tokens_table.php';
 $migration->up();
 verify($capsule->schema()->hasTable('fleetops_navigator_link_tokens'),'REAL_MIGRATION_UP');
 $indexRows=DB::select("SHOW INDEX FROM fleetops_navigator_link_tokens WHERE Key_name IN ('fleetops_nav_link_hash_unique','fleetops_nav_link_issuer_idx','fleetops_nav_link_lifecycle_idx')");
 verify(count($indexRows)>=3,'REAL_MIGRATION_INDEXES');
 Company::create(['uuid'=>'company-B']);User::create(['uuid'=>'admin-B','company_uuid'=>'company-B','type'=>'admin','status'=>'active']);
 require '/lab/fork/server/src/Support/NavigatorLinkTokenService.php';
 $issuer=User::with('company')->where('uuid','admin-B')->firstOrFail();
 $service=new NavigatorLinkTokenService();
 $token=$service->issue($issuer);
 verify(strlen($token)===64,'TOKEN_ISSUED');
 // Simultaneous different PHP child processes, each opens its own PDO connection.
 // The first holds the InnoDB lock briefly, the other must wait and fail cleanly.
 $children=[];
 for ($i=0;$i<2;$i++){
   $pid=pcntl_fork();
   if($pid===-1)throw new RuntimeException('fork_failed');
   if($pid===0){
     try{
       // Avoid using the inherited MySQL socket following fork.
       $capsule->getDatabaseManager()->purge('default');
       $s=new NavigatorLinkTokenService();
       $result=$s->redeemWith($token,function(User $u):string {
         usleep(350000);
         return $u->company_uuid;
       });
       exit($result==='company-B'?10:($result===null?20:30));
     } catch(Throwable $e){exit(40);}
   }
   $children[]=$pid;
 }
 $codes=[];
 foreach($children as $pid){
   pcntl_waitpid($pid,$status);
   $codes[]=pcntl_wexitstatus($status);
 }
 sort($codes);
 verify($codes===[10,20],'TRUE_PARALLEL_ONE_WINNER_ONE_REPLAY');
 $row=DB::table('fleetops_navigator_link_tokens')->where('token_hash',hash('sha256',$token))->first();
 verify($row->consumed_at!==null,'ONE_CONSUMED_ROW');
 verify($service->redeem($token)===null,'POST_RACE_REPLAY_DENIED');
 $migration->down();
 verify(!$capsule->schema()->hasTable('fleetops_navigator_link_tokens'),'REAL_MIGRATION_DOWN');
 echo "R68_REAL_MIGRATION_AND_CONCURRENCY=7/7\n";
}
