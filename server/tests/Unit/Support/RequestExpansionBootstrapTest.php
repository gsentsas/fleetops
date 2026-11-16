<?php

use Illuminate\Http\Request;

test('request bootstrap installs the native ordered parameter helper', function ($first) {
    $request = new Request(['primary' => $first, 'secondary' => 'second']);
    expect($request->or(['primary', 'secondary'], 'fallback'))->toBe($first);
})->with([['first'], [0], [false], [''], [[]], [null]]);

test('request helper checks fallback keys in their declared order', function () {
    $request = new Request(['secondary' => 'second']);
    expect($request->or(['primary', 'secondary'], 'fallback'))->toBe('second');
});

test('request helper returns the explicit default when no key exists', function () {
    expect((new Request())->or(['missing'], 'fallback'))->toBe('fallback')
        ->and((new Request())->or())->toBeNull();
});

test('request bootstrap binds a default request without booting HTTP providers', function () {
    expect(app()->bound('request'))->toBeTrue()
        ->and(app('request'))->toBeInstanceOf(Request::class);
});

test('component bootstrap reports its actual CLI execution context', function () {
    expect(app()->runningInConsole())->toBe(in_array(PHP_SAPI, ['cli', 'phpdbg'], true));
});
