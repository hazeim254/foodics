<?php

use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves UserContext as scoped binding', function () {
    $first = app(UserContext::class);
    $second = app(UserContext::class);

    expect($first)->toBe($second);
});

it('resolves DaftraApiClient as scoped binding', function () {
    $user = User::factory()->create();
    $user->providerTokens()->create([
        'provider' => 'daftra',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addDay(),
    ]);

    app(UserContext::class)->set($user);

    $first = app(DaftraApiClient::class);
    $second = app(DaftraApiClient::class);

    expect($first)->toBe($second);
});

it('resolves FoodicsApiClient as scoped binding', function () {
    $user = User::factory()->create();
    $user->providerTokens()->create([
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addDay(),
    ]);

    app(UserContext::class)->set($user);

    $first = app(FoodicsApiClient::class);
    $second = app(FoodicsApiClient::class);

    expect($first)->toBe($second);
});
