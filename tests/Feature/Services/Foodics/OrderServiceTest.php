<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Foodics\OrderService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches all new orders across multiple pages', function () {
    $page1Data = [
        'data' => [
            ['id' => 'uuid-1', 'reference' => '00100'],
            ['id' => 'uuid-2', 'reference' => '00200'],
        ],
    ];
    $page2Data = [
        'data' => [
            ['id' => 'uuid-3', 'reference' => '00300'],
        ],
    ];
    $page3Data = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]'])))
        ->once()
        ->andReturn(fakeResponse($page1Data));
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00200'))
        ->once()
        ->andReturn(fakeResponse($page2Data));
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00300'))
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
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00500'))
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
        ->with('/v5/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]'])))
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
        ->with('/v5/orders', Mockery::any())
        ->once()
        ->andReturn(fakeResponse($pageData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toBeEmpty();
});

it('fetches a single order by ID', function () {
    $orderId = 'order-uuid-123';
    $orderData = [
        'order' => [
            'id' => $orderId,
            'reference' => '00420',
            'business_date' => '2026-04-14',
            'products' => [],
            'payments' => [],
            'charges' => [],
            'customer' => null,
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with("/orders/{$orderId}", Mockery::on(fn ($p) => $p['include'] === 'payments,charges,customer,products'))
        ->once()
        ->andReturn(fakeResponse($orderData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $order = $orderService->getOrder($orderId);

    expect($order['id'])->toBe($orderId);
    expect($order['reference'])->toBe('00420');
});

it('throws exception when fetching a single order returns 404', function () {
    $orderId = 'non-existent-order-id';

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with("/orders/{$orderId}", Mockery::any())
        ->once()
        ->andReturn(failedResponse(404));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);

    expect(fn () => $orderService->getOrder($orderId))
        ->toThrow(RequestException::class);
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

function failedResponse(int $status = 404): object
{
    return new class($status)
    {
        public function __construct(private int $status) {}

        public function successful(): bool
        {
            return false;
        }

        public function failed(): bool
        {
            return true;
        }

        public function throw(): void
        {
            throw new RequestException(
                new Illuminate\Http\Client\Response(
                    new Response($this->status, [], json_encode(['error' => 'Not found']))
                )
            );
        }

        public function json($key = null, $default = null): mixed
        {
            return $default;
        }
    };
}
