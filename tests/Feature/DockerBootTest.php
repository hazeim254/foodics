<?php

it('has pgsql database connection configured', function () {
    expect(config('database.default'))->toBe(env('DB_CONNECTION', 'sqlite'));
});

it('can connect to the database', function () {
    $pdo = DB::connection()->getPdo();

    expect($pdo)->not->toBeNull();
});

it('has required php extensions loaded', function () {
    $required = ['pdo', 'pdo_pgsql', 'mbstring', 'tokenizer', 'xml', 'curl', 'openssl'];

    foreach ($required as $ext) {
        expect(extension_loaded($ext))->toBeTrue("Extension {$ext} should be loaded");
    }
});
