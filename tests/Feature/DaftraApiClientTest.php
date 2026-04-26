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

it('fetches branch list from Daftra', function () {
    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Main Branch'],
                ['id' => 2, 'name' => 'Branch 2'],
            ],
        ], 200),
    ]);

    $client = $this->app->make(DaftraApiClient::class);
    $branches = $client->getBranches();

    expect($branches)->toHaveCount(2);
    expect($branches[0])->toBe(['id' => 1, 'name' => 'Main Branch']);
    expect($branches[1])->toBe(['id' => 2, 'name' => 'Branch 2']);
});

it('returns empty array when branch list response has no data', function () {
    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response([], 200),
    ]);

    $client = $this->app->make(DaftraApiClient::class);
    $branches = $client->getBranches();

    expect($branches)->toBe([]);
});

it('throws exception when branch list request fails', function () {
    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response('Unauthorized', 401),
        '*/oauth/token*' => Http::response([
            'access_token' => 'new-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $client = $this->app->make(DaftraApiClient::class);
    $client->getBranches();
})->throws(RuntimeException::class, 'Daftra branch list request failed: HTTP 401');

it('returns null from tryGetBranches when branch list request fails', function () {
    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response(['error' => 'Entity Not exists'], 200),
        '*/oauth/token*' => Http::response([
            'access_token' => 'new-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $client = $this->app->make(DaftraApiClient::class);
    $branches = $client->tryGetBranches();

    expect($branches)->toBeNull();
});

it('returns branches from tryGetBranches when request succeeds', function () {
    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Main Branch'],
            ],
        ], 200),
    ]);

    $client = $this->app->make(DaftraApiClient::class);
    $branches = $client->tryGetBranches();

    expect($branches)->toHaveCount(1);
    expect($branches[0])->toBe(['id' => 1, 'name' => 'Main Branch']);
});
