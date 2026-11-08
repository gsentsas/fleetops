<?php

use Geocoder\Laravel\Http\LaravelHttpClient;
use Geocoder\Provider\Chain\Chain;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Psr\Http\Client\ClientInterface;

test('published fleet ops geocoder configuration uses the Laravel HTTP PSR-18 adapter', function () {
    $config = require dirname(__DIR__) . '/config/geocoder.php';

    expect($config)->toBeArray()
        ->and($config['adapter'])->toBe(LaravelHttpClient::class)
        ->and(class_exists(LaravelHttpClient::class))->toBeTrue()
        ->and(is_subclass_of($config['adapter'], ClientInterface::class))->toBeTrue()
        ->and($config['providers'])->toHaveKey(Chain::class)
        ->and($config['providers'][Chain::class])->toHaveKey(GoogleMaps::class);
});
