<?php

use App\Enums\SettingKey;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['daftra_id' => '12345']);
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'daftra',
        'token' => 'fake-token',
        'refresh_token' => 'fake-refresh',
        'expires_at' => now()->addHour(),
    ]);
    Context::add('user', $this->user);
});

it('appends request_branch_id to GET requests when branch ID is not 1', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultBranchId, '2');

    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->get('/api2/products');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'request_branch_id=2');
    });
});

it('appends request_branch_id to POST requests when branch ID is not 1', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultBranchId, '15');

    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->post('/api2/invoices', ['Invoice' => []]);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'request_branch_id=15');
    });
});

it('does not append request_branch_id when branch ID is 1', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultBranchId, '1');

    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->get('/api2/products');

    Http::assertSent(function (Request $request) {
        return ! str_contains($request->url(), 'request_branch_id');
    });
});

it('does not append request_branch_id when branch ID is null', function () {
    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->get('/api2/products');

    Http::assertSent(function (Request $request) {
        return ! str_contains($request->url(), 'request_branch_id');
    });
});

it('preserves existing query parameters when appending branch ID', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultBranchId, '3');

    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->get('/api2/products?page=1&limit=10');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'limit=10')
            && str_contains($request->url(), 'request_branch_id=3');
    });
});

it('does not duplicate request_branch_id if already present in URL', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultBranchId, '5');

    Http::fake();

    $client = $this->app->make(DaftraApiClient::class);
    $client->get('/api2/products?request_branch_id=5');

    $url = Http::recorded()[0][0]->url();

    expect(substr_count($url, 'request_branch_id'))->toBe(1);
});
