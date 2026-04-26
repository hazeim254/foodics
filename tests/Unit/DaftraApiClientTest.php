<?php

uses(Tests\TestCase::class);

use App\Enums\SettingKey;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use Tests\TestCase;

it('appends request_branch_id to GET requests when branch ID is not 1', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn('2');
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/products');

    expect($result)->toBe('/api2/products?request_branch_id=2');
});

it('appends request_branch_id to POST requests when branch ID is not 1', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn('15');
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/invoices');

    expect($result)->toBe('/api2/invoices?request_branch_id=15');
});

it('does not append request_branch_id when branch ID is 1', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn('1');
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/products');

    expect($result)->toBe('/api2/products');
});

it('does not append request_branch_id when branch ID is null', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn(null);
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/products');

    expect($result)->toBe('/api2/products');
});

it('does not append request_branch_id when branch ID is empty string', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn('');
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/products');

    expect($result)->toBe('/api2/products');
});

it('preserves existing query parameters when appending branch ID', function () {
    $token = mock(ProviderToken::class)->makePartial();
    $token->token = 'fake-token';

    $user = mock(User::class)->makePartial();
    $user->daftra_id = '123';
    $user->shouldReceive('setting')
        ->with(SettingKey::DaftraDefaultBranchId)
        ->andReturn('3');
    $user->shouldReceive('getDaftraToken')->andReturn($token);

    $client = new DaftraApiClient($user);

    $method = new ReflectionMethod($client, 'appendBranchIdToUrl');
    $result = $method->invoke($client, '/api2/products?page=1&limit=10');

    expect($result)->toBe('/api2/products?page=1&limit=10&request_branch_id=3');
});
