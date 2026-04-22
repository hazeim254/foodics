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

it('returns null when Daftra returns no rows for the name', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'VAT', 'rate' => 15]);

    expect($result)->toBeNull();
});

it('returns the Daftra id when both name and value match', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 42, 'name' => 'VAT', 'value' => 15]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'VAT', 'rate' => 15]);

    expect($result)->toBe(42);
});

it('skips rows whose value does not match the Foodics rate', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 10, 'name' => 'VAT', 'value' => 5]],
                ['Tax' => ['id' => 20, 'name' => 'VAT', 'value' => 15]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'VAT', 'rate' => 15]);

    expect($result)->toBe(20);
});

it('returns null when Daftra has the name but no matching value', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 1, 'name' => 'VAT', 'value' => 5]],
                ['Tax' => ['id' => 2, 'name' => 'VAT', 'value' => 10]],
                ['Tax' => ['id' => 3, 'name' => 'VAT', 'value' => 12]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'VAT', 'rate' => 15]);

    expect($result)->toBeNull();
});

it('creates a new Daftra tax via resolveTaxId when no row matches on both name and value', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 1, 'name' => 'VAT', 'value' => 5]],
            ],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::on(fn (array $payload) => $payload['Tax']['name'] === 'VAT' && $payload['Tax']['value'] === 15.0))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 99]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $daftraId = $service->resolveTaxId(['id' => 'foodics-tax-1', 'name' => 'VAT', 'rate' => 15]);

    expect($daftraId)->toBe(99);
    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'tax')->where('foodics_id', 'foodics-tax-1')->where('daftra_id', 99)->exists())->toBeTrue();
});

it('persists an EntityMapping when resolveTaxId finds a name+value match', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 42, 'name' => 'VAT', 'value' => 15]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $daftraId = $service->resolveTaxId(['id' => 'foodics-tax-2', 'name' => 'VAT', 'rate' => 15]);

    expect($daftraId)->toBe(42);

    $mapping = EntityMapping::where('user_id', $this->user->id)->where('type', 'tax')->where('foodics_id', 'foodics-tax-2')->first();
    expect($mapping)->not->toBeNull();
    expect($mapping->daftra_id)->toBe(42);
    expect($mapping->metadata['name'])->toBe('VAT');
    expect($mapping->metadata['rate'])->toBe(15);
});

it('hits the EntityMapping cache on subsequent resolutions', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'tax',
        'foodics_id' => 'foodics-tax-3',
        'daftra_id' => 777,
        'metadata' => ['name' => 'VAT', 'rate' => 15],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');
    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $daftraId = $service->resolveTaxId(['id' => 'foodics-tax-3', 'name' => 'VAT', 'rate' => 15]);

    expect($daftraId)->toBe(777);
});

it('returns null immediately when Foodics tax has no name', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');
    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => null, 'rate' => 15]);

    expect($result)->toBeNull();
});

it('treats null Foodics rate as 0.0 for matching', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'NoTax'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 55, 'name' => 'NoTax', 'value' => 0]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'NoTax', 'rate' => null]);

    expect($result)->toBe(55);
});

it('casts value comparison through float', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => $args['filter']['name'] === 'VAT'))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 33, 'name' => 'VAT', 'value' => '5']],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $result = $service->getTax(['name' => 'VAT', 'rate' => 5]);

    expect($result)->toBe(33);
});

it('sends limit=100 on the tax list request', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['limit']) && $args['limit'] === 100))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $service->getTax(['name' => 'VAT', 'rate' => 15]);
});
