<?php

use Illuminate\Foundation\Application;

it('boots the application successfully', function () {
    $app = app();

    expect($app)->toBeInstanceOf(Application::class);
    expect($app->isBooted())->toBeTrue();
});

it('has octane cache store configured', function () {
    expect(config('cache.stores.octane'))->not->toBeNull();
    expect(config('cache.stores.octane.driver'))->toBe('octane');
});

it('has octane config file', function () {
    expect(config('octane'))->not->toBeNull();
    expect(config('octane.server'))->toBe('swoole');
});
