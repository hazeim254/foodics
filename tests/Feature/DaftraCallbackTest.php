<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.daftra.base_url' => 'https://api.daftra.test']);
    config(['services.daftra.client_id' => 'test-client-id']);
    config(['services.daftra.client_secret' => 'test-client-secret']);
    config(['services.daftra.redirect_uri' => 'https://app.test/daftra/callback']);
});

it('exchanges daftra code for tokens and fetches site info', function () {
    Http::fake([
        'api.daftra.test/oauth/token' => Http::response([
            'access_token' => 'daftra-access-token',
            'refresh_token' => 'daftra-refresh-token',
            'expires_in' => 3600,
            'site_id' => '12345',
            'subdomain' => 'myshop.daftra.com',
        ]),
        'api.daftra.test/api2/site_info' => Http::response([
            'result' => 'successful',
            'code' => 200,
            'data' => [
                'Site' => [
                    'id' => '12345',
                    'business_name' => 'My Shop',
                    'subdomain' => 'myshop.daftra.com',
                ],
            ],
        ]),
    ]);

    $response = $this->get(route('daftra.callback', ['code' => 'test-code']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('daftra_account', [
        'site_id' => '12345',
        'subdomain' => 'myshop.daftra.com',
        'business_name' => 'My Shop',
        'access_token' => 'daftra-access-token',
        'refresh_token' => 'daftra-refresh-token',
        'expires_in' => 3600,
    ]);
});

it('creates user with business name in daftra meta when both providers are connected', function () {
    Http::fake([
        'api.daftra.test/oauth/token' => Http::response([
            'access_token' => 'daftra-access-token',
            'refresh_token' => 'daftra-refresh-token',
            'expires_in' => 3600,
            'site_id' => '12345',
            'subdomain' => 'myshop.daftra.com',
        ]),
        'api.daftra.test/api2/site_info' => Http::response([
            'result' => 'successful',
            'code' => 200,
            'data' => [
                'Site' => [
                    'id' => '12345',
                    'business_name' => 'My Shop',
                    'subdomain' => 'myshop.daftra.com',
                ],
            ],
        ]),
    ]);

    $this->withSession([
        'foodics_account' => [
            'business_id' => 'uuid-123',
            'business_ref' => '67890',
            'business_name' => 'Foodics Restaurant',
            'user_name' => 'John Doe',
            'user_email' => 'john@example.com',
            'access_token' => 'foodics-access-token',
            'refresh_token' => 'foodics-refresh-token',
            'expires_in' => 3600,
        ],
    ]);

    $response = $this->get(route('daftra.callback', ['code' => 'test-code']));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::first();
    expect($user->daftra_id)->toBe('12345');
    expect($user->daftra_meta)->toBe([
        'subdomain' => 'myshop.daftra.com',
        'business_name' => 'My Shop',
    ]);
    expect($user->foodics_ref)->toBe('67890');
});

it('fails when site info request fails', function () {
    Http::fake([
        'api.daftra.test/oauth/token' => Http::response([
            'access_token' => 'daftra-access-token',
            'refresh_token' => 'daftra-refresh-token',
            'expires_in' => 3600,
            'site_id' => '12345',
            'subdomain' => 'myshop.daftra.com',
        ]),
        'api.daftra.test/api2/site_info' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $response = $this->get(route('daftra.callback', ['code' => 'test-code']));

    $response->assertServerError();
});
