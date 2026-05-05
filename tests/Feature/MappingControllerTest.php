<?php

use App\Models\EntityMapping;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\BranchService;
use App\Services\Foodics\TaxService as FoodicsTaxService;
use App\Services\Daftra\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'foodics',
        'token' => 'fake-foodics-token',
        'refresh_token' => 'fake-foodics-refresh',
        'expires_at' => now()->addHour(),
    ]);
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'daftra',
        'token' => 'fake-daftra-token',
        'refresh_token' => 'fake-daftra-refresh',
        'expires_at' => now()->addHour(),
    ]);
});

it('shows the mapping page', function () {
    $this->actingAs($this->user)
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Mappings')
        ->assertSee('Sync Branches')
        ->assertSee('Sync Taxes');
});

it('redirects unauthenticated users from GET /mappings', function () {
    $this->get('/mappings')->assertRedirect('/login');
});

it('syncs branches from Foodics and Daftra', function () {
    $mockFoodicsClient = Mockery::mock(\App\Services\Foodics\FoodicsApiClient::class);
    $mockFoodicsClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::any())
        ->once()
        ->andReturn(new class
        {
            public function successful() { return true; }
            public function failed() { return false; }
            public function throw() { return $this; }
            public function json($key = null, $default = null) {
                $data = [
                    'data' => [
                        ['id' => 'fb-1', 'name' => 'Foodics Branch 1', 'reference' => 'B01'],
                    ],
                ];
                return $key === null ? $data : data_get($data, $key, $default);
            }
        });
    $this->app->instance(\App\Services\Foodics\FoodicsApiClient::class, $mockFoodicsClient);

    $mockDaftraClient = Mockery::mock(DaftraApiClient::class);
    $mockDaftraClient->shouldReceive('tryGetBranches')
        ->once()
        ->andReturn([['id' => 1, 'name' => 'Main Branch']]);
    $this->app->instance(DaftraApiClient::class, $mockDaftraClient);

    $this->actingAs($this->user)
        ->post('/mappings/branches/sync')
        ->assertRedirect('/mappings');
});

it('syncs taxes from Foodics and Daftra', function () {
    $mockFoodicsClient = Mockery::mock(\App\Services\Foodics\FoodicsApiClient::class);
    $mockFoodicsClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(new class
        {
            public function successful() { return true; }
            public function failed() { return false; }
            public function throw() { return $this; }
            public function json($key = null, $default = null) {
                $data = [
                    'data' => [
                        ['id' => 'ft-1', 'name' => 'VAT', 'rate' => 15],
                    ],
                ];
                return $key === null ? $data : data_get($data, $key, $default);
            }
        });
    $this->app->instance(\App\Services\Foodics\FoodicsApiClient::class, $mockFoodicsClient);

    $mockDaftraClient = Mockery::mock(DaftraApiClient::class);
    $mockDaftraClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 10, 'name' => 'VAT', 'value' => 15]],
            ],
        ]));
    $this->app->instance(DaftraApiClient::class, $mockDaftraClient);

    $this->actingAs($this->user)
        ->post('/mappings/taxes/sync')
        ->assertRedirect('/mappings');
});

it('stores branch mappings', function () {
    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => '2'],
                ['foodics_id' => 'fb-2', 'daftra_id' => ''],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->count())->toBe(1);

    $mapping = EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->first();
    expect($mapping->foodics_id)->toBe('fb-1');
    expect($mapping->daftra_id)->toBe(2);
    expect($mapping->status)->toBe('synced');
});

it('stores tax mappings', function () {
    $this->actingAs($this->user)
        ->post('/mappings/taxes', [
            'mappings' => [
                ['foodics_id' => 'ft-1', 'daftra_id' => '10'],
            ],
        ])
        ->assertRedirect('/mappings');

    $mapping = EntityMapping::where('user_id', $this->user->id)
        ->where('type', 'tax')
        ->where('foodics_id', 'ft-1')
        ->first();

    expect($mapping)->not->toBeNull();
    expect($mapping->daftra_id)->toBe(10);
});

it('updates an existing branch mapping on re-save', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'fb-1',
        'daftra_id' => 1,
        'status' => 'synced',
    ]);

    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => '5'],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->count())->toBe(1);
    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->first()->daftra_id)->toBe(5);
});

it('removes branch mappings when daftra_id is empty', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'fb-1',
        'daftra_id' => 2,
        'status' => 'synced',
    ]);

    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => ''],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->exists())->toBeFalse();
});

it('validates branch mapping input', function () {
    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => 'not-an-array',
        ])
        ->assertSessionHasErrors('mappings');
});

it('validates tax mapping input', function () {
    $this->actingAs($this->user)
        ->post('/mappings/taxes', [
            'mappings' => 'not-an-array',
        ])
        ->assertSessionHasErrors('mappings');
});