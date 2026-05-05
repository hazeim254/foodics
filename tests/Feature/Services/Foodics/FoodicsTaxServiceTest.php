<?php

use App\Models\User;
use App\Services\Foodics\TaxService;
use App\Services\Foodics\FoodicsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches all taxes from Foodics', function () {
    $taxesData = [
        'data' => [
            ['id' => 'tax-1', 'name' => 'VAT', 'rate' => 15],
            ['id' => 'tax-2', 'name' => 'VAT', 'rate' => 5],
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsTaxResponse($taxesData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $taxes = $service->fetchTaxes();

    expect($taxes)->toHaveCount(2);
    expect($taxes[0]['id'])->toBe('tax-1');
    expect($taxes[1]['rate'])->toBe(5);
});

it('returns empty array when no taxes exist', function () {
    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsTaxResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    expect($service->fetchTaxes())->toBe([]);
});

it('fetches taxes across multiple pages using cursor pagination', function () {
    $page1 = ['data' => array_map(fn ($i) => ['id' => "tax-$i", 'name' => "Tax $i", 'rate' => $i], range(1, 50))];
    $page2 = ['data' => [['id' => 'tax-51', 'name' => 'Tax 51', 'rate' => 51]]];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::on(fn ($p) => ! isset($p['after'])))
        ->once()
        ->andReturn(fakeFoodicsTaxResponse($page1, 'cursor-page-2'));
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::on(fn ($p) => ($p['after'] ?? null) === 'cursor-page-2'))
        ->once()
        ->andReturn(fakeFoodicsTaxResponse($page2, null));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $taxes = $service->fetchTaxes();

    expect($taxes)->toHaveCount(51);
});

function fakeFoodicsTaxResponse(array $json, ?string $nextCursor = null): object
{
    if ($nextCursor !== null) {
        $json['meta'] = ['next_cursor' => $nextCursor];
    }

    return new class($json)
    {
        public function __construct(private array $json) {}

        public function successful(): bool
        {
            return true;
        }

        public function failed(): bool
        {
            return false;
        }

        public function throw(): static
        {
            return $this;
        }

        public function json($key = null, $default = null): mixed
        {
            if ($key === null) {
                return $this->json;
            }

            return data_get($this->json, $key, $default);
        }
    };
}