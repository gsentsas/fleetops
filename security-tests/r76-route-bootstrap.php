<?php
declare(strict_types=1);
require '/lab/tools/r66-bootstrap.php';
$container=\Illuminate\Container\Container::getInstance();
$config=new \Illuminate\Config\Repository([
  'fleetops'=>[
     'api'=>['routing'=>['prefix'=>'fleet-ops','internal_prefix'=>'int']],
  ],
]);
$container->instance('config',$config);
$container->instance(\Illuminate\Contracts\Config\Repository::class,$config);
