<?php
declare(strict_types=1);
// Disposable, isolated MySQL. No QA/prod metadata or secrets.
require '/fleetbase/api/vendor/autoload.php';
$cap=new \Illuminate\Database\Capsule\Manager();
$cap->addConnection([
 'driver'=>'mysql','host'=>'gst-r77-mysql','port'=>3306,
 'database'=>'synthetic_r77','username'=>'root','password'=>'',
 'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'',
],'mysql');
$cap->getDatabaseManager()->setDefaultConnection('mysql');
$cap->setAsGlobal();
$cap->bootEloquent();
$container=$cap->getContainer();
$container->instance('db',$cap->getDatabaseManager());
$container->instance('db.schema',$cap->schema());
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
\Illuminate\Container\Container::setInstance($container);
$schema=$cap->schema('mysql');
$files=[
 '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094305_create_companies_table.php',
 '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094301_create_users_table.php',
 '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094311_create_settings_table.php',
 '/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094311_create_api_credentials_table.php',
 '/fleetbase/api/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php',
];
foreach($files as $file) {
 $mig=require $file;
 $mig->up();
 echo 'R77_CORE_MIGRATION='.basename($file).' PASS'.PHP_EOL;
}
foreach(['companies','users','settings','api_credentials','personal_access_tokens'] as $name) {
 if (!$schema->hasTable($name))throw new RuntimeException('Missing required table');
}
echo 'R77_DISPOSABLE_CORE_TABLES=5/5 PASS'.PHP_EOL;
