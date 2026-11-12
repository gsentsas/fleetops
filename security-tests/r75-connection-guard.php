<?php
declare(strict_types=1);
require '/fleetbase/api/vendor/autoload.php';
require '/lab/fork/server/src/Support/NavigatorDatabaseGuard.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Fleetbase\FleetOps\Support\NavigatorDatabaseGuard;

$case=$argv[1] ?? 'aligned';
if (!in_array($case,['aligned','bad-default','bad-credential'],true))exit(2);
$cap=new Capsule();
$cfg=['driver'=>'mysql','host'=>'127.0.0.1','database'=>'not_used','username'=>'not_used','password'=>''];
$cap->addConnection($cfg,'mysql');
$cap->addConnection($cfg,'default');
$cap->getDatabaseManager()->setDefaultConnection($case==='bad-default'?'default':'mysql');
$cap->getContainer()->get('config')->set('fleetbase.connection.db',$case==='bad-credential'?'other':'mysql');
$cap->setAsGlobal();
$cap->bootEloquent();
$container=$cap->getContainer();
$container->instance('db',$cap->getDatabaseManager());
Facade::setFacadeApplication($container);
\Illuminate\Container\Container::setInstance($container);
$denied=false;
try{NavigatorDatabaseGuard::assertAtomic();}
catch(\RuntimeException $e){$denied=true;}
$expectDenied=$case!=='aligned';
if($denied!==$expectDenied)throw new \RuntimeException('connection_guard_assertion_failed');
echo 'R75_CONNECTION_GUARD_'.strtoupper(str_replace('-','_',$case)).'=PASS'.PHP_EOL;
