<?php
$target = 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\NavigatorController';
$service = 'Fleetbase\\FleetOps\\Support\\NavigatorLinkTokenService';
foreach ([$target, $service] as $class) {
    $r = new ReflectionClass($class);
    echo $r->getShortName() . '_SOURCE=' . $r->getFileName() . PHP_EOL;
    if (!str_starts_with($r->getFileName(), '/lab/fork/server/src/')) {
        throw new RuntimeException('wrong autoload source');
    }
}
echo "R66_SOURCE_PIN=PASS\n";
