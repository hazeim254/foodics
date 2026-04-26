<?php

use App\Models\EntityMapping;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('resolves tax id from local cache', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'VAT',
        'rate' => 5,
    ];

    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'tax',
        'foodics_id' => '8d84bebc',
        'daftra_id' => 12345,
        'metadata' => ['name' => 'VAT', 'rate' => 5],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->resolveTaxId($foodicsTax);

    expect($daftraId)->toBe(12345);
});

it('searches daftra when not cached and creates mapping', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'VAT',
        'rate' => 5,
    ];

    $taxFoundResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['Tax' => ['id' => 67890, 'name' => 'VAT', 'value' => 5]],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxFoundResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->resolveTaxId($foodicsTax);

    expect($daftraId)->toBe(67890);
    expect(EntityMapping::where('foodics_id', '8d84bebc')->where('daftra_id', 67890)->exists())->toBeTrue();
});

it('creates tax in daftra when not found and persists mapping', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'VAT',
        'rate' => 5,
    ];

    $taxNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    $taxCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 99999]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxNotFoundResponse);

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', [
            'Tax' => [
                'name' => 'VAT',
                'value' => 5.0,
                'included' => 0,
            ],
        ])
        ->once()
        ->andReturn($taxCreateResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->resolveTaxId($foodicsTax);

    expect($daftraId)->toBe(99999);
    expect(EntityMapping::where('foodics_id', '8d84bebc')->where('daftra_id', 99999)->exists())->toBeTrue();
});

it('searches daftra by name when getting tax', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'VAT',
        'rate' => 5,
    ];

    $taxFoundResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['Tax' => ['id' => 54321, 'name' => 'VAT', 'value' => 5]],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => ($args['filter']['name'] ?? null) === 'VAT'))
        ->once()
        ->andReturn($taxFoundResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->getTax($foodicsTax);

    expect($daftraId)->toBe(54321);
});

it('returns null when tax not found in daftra', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'VAT',
        'rate' => 5,
    ];

    $taxNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxNotFoundResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->getTax($foodicsTax);

    expect($daftraId)->toBeNull();
});

it('creates tax with correct payload', function () {
    $foodicsTax = [
        'id' => '8d84bebc',
        'name' => 'Service Tax',
        'rate' => 10,
    ];

    $taxCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::on(function (array $payload) {
            expect($payload['Tax']['name'])->toBe('Service Tax');
            expect($payload['Tax']['value'])->toBe(10.0);
            expect($payload['Tax']['included'])->toBe(0);

            return true;
        }))
        ->once()
        ->andReturn($taxCreateResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $taxService = $this->app->make(TaxService::class);
    $daftraId = $taxService->createTax($foodicsTax);

    expect($daftraId)->toBe(11111);
});
