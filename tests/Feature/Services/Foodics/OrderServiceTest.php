<?php

use App\Enums\InvoiceSyncStatus;
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
        ->with('/v5/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]']) && hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
        ->once()
        ->andReturn(fakeResponse($page1Data));
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00200' && hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
        ->once()
        ->andReturn(fakeResponse($page2Data));
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00300' && hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
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
        ->with('/v5/orders', Mockery::on(fn ($p) => isset($p['filter[reference_after]']) && $p['filter[reference_after]'] === '00500' && hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
        ->once()
        ->andReturn(fakeResponse($pageData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $orders = $orderService->fetchNewOrders();

    expect($orders)->toBeEmpty();
});

it('ignores pending and failed invoices when computing the reference_after cursor', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_reference' => '00100',
        'status' => InvoiceSyncStatus::Synced,
    ]);
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_reference' => '00999',
        'daftra_id' => null,
        'status' => InvoiceSyncStatus::Pending,
    ]);
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_reference' => '00900',
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ($p['filter[reference_after]'] ?? null) === '00100'))
        ->once()
        ->andReturn(fakeResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $this->app->make(OrderService::class)->fetchNewOrders();
});

it('omits reference_after param on first sync', function () {
    $pageData = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ! isset($p['filter[reference_after]']) && hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
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
        ->with('/v5/orders', Mockery::on(fn ($p) => hasFullIncludes($p) && ($p['filter[status]'] ?? null) === '4,5'))
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
        'data' => [
            [
                'id' => $orderId,
                'reference' => '00420',
                'business_date' => '2026-04-14',
                'products' => [],
                'payments' => [],
                'charges' => [],
                'customer' => null,
            ],
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ($p['filter[id]'] ?? null) === $orderId
            && hasFullIncludes($p)
            && ! isset($p['filter[status]'])))
        ->once()
        ->andReturn(fakeResponse($orderData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);
    $order = $orderService->getOrder($orderId);

    expect($order['id'])->toBe($orderId);
    expect($order['reference'])->toBe('00420');
});

it('returns empty array when fetching a single order returns no results', function () {
    $orderId = 'non-existent-order-id';

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ($p['filter[id]'] ?? null) === $orderId))
        ->once()
        ->andReturn(fakeResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);

    expect($orderService->getOrder($orderId))->toBe([]);
});

it('throws exception when fetching a single order request fails', function () {
    $orderId = 'order-uuid-123';

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::any())
        ->once()
        ->andReturn(failedResponse(500));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $orderService = $this->app->make(OrderService::class);

    expect(fn () => $orderService->getOrder($orderId))
        ->toThrow(RequestException::class);
});

it('requests all include paths recommended by the accounting guide', function () {
    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(function ($p) {
            $include = $p['include'] ?? '';

            $requiredPaths = [
                'branch',
                'charges',
                'payments.payment_method',
                'discount',
                'products',
                'products.taxes',
                'charges.taxes',
                'products.product',
                'products.options',
                'products.options.modifier_option',
                'combos.products',
                'charges.charge',
                'products.discount',
                'combos.discount',
                'combos.products.options.taxes',
                'combos.products.taxes',
                'products.options.taxes',
                'customer',
            ];

            foreach ($requiredPaths as $path) {
                if (! str_contains($include, $path)) {
                    return false;
                }
            }

            return true;
        }))
        ->once()
        ->andReturn(fakeResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $this->app->make(OrderService::class)->fetchNewOrders();
});

it('restricts fetch to completed and returned orders (status 4,5)', function () {
    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ($p['filter[status]'] ?? null) === '4,5'))
        ->once()
        ->andReturn(fakeResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $this->app->make(OrderService::class)->fetchNewOrders();

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/orders', Mockery::on(fn ($p) => ! isset($p['filter[status]'])))
        ->once()
        ->andReturn(fakeResponse(['data' => [['id' => 'test', 'reference' => '001']]]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $this->app->make(OrderService::class)->getOrder('test');
});

function hasFullIncludes(array $params): bool
{
    $include = $params['include'] ?? '';

    $requiredPaths = [
        'branch',
        'charges',
        'payments.payment_method',
        'discount',
        'products',
        'products.taxes',
        'charges.taxes',
        'products.product',
        'products.options',
        'products.options.modifier_option',
        'combos.products',
        'charges.charge',
        'products.discount',
        'combos.discount',
        'combos.products.options.taxes',
        'combos.products.taxes',
        'products.options.taxes',
        'customer',
    ];

    foreach ($requiredPaths as $path) {
        if (! str_contains($include, $path)) {
            return false;
        }
    }

    return true;
}

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
