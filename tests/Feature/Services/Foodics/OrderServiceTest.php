<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Foodics\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches all new orders across multiple pages', function () {
    $page1Data = [
        'data' => [
            ['order' => ['id' => 'uuid-1', 'reference' => '00100']],
            ['order' => ['id' => 'uuid-2', 'reference' => '00200']],
        ],
    ];
    $page2Data = [
        'data' => [
            ['order' => ['id' => 'uuid-3', 'reference' => '00300']],
        ],
    ];
    $page3Data = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]'])))
        ->once()
        ->andReturn(fakeResponse($page1Data));
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00200'))
        ->once()
        ->andReturn(fakeResponse($page2Data));
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00300'))
        ->once()
        ->andReturn(fakeResponse($page3Data));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toHaveCount(3);
    expect($orders[0]['reference'])->toBe('00100');
    expect($orders[1]['reference'])->toBe('00200');
    expect($orders[2]['reference'])->toBe('00300');
});

it('includes reference_after param when max foodics_reference exists', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_reference' => '00500',
    ]);

    $pageData = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00500'))
        ->once()
        ->andReturn(fakeResponse($pageData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toBeEmpty();
});

it('omits reference_after param on first sync', function () {
    $pageData = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]'])))
        ->once()
        ->andReturn(fakeResponse($pageData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toBeEmpty();
});

it('returns empty array when API returns empty page', function () {
    $pageData = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/orders', Mockery::any())
        ->once()
        ->andReturn(fakeResponse($pageData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toBeEmpty();
});

function fakeResponse(array $json): object
{
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

        public function json($key = null, $default = null): mixed
        {
            if ($key === null) {
                return $this->json;
            }

            return data_get($this->json, $key, $default);
        }
    };
}
