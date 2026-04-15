<?php

use App\Models\User;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Foodics\ProductService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches a product by id', function () {
    $productId = '8d90b8d1';
    $payload = [
        'data' => [
            'id' => $productId,
            'name' => 'Canonical Tuna Sandwich',
            'sku' => 'P002-CANONICAL',
            'description' => 'Canonical Description',
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with("/v5/products/{$productId}")
        ->once()
        ->andReturn(fakeFoodicsResponse($payload));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);
    $product = $service->getProduct($productId);

    expect($product['id'])->toBe($productId)
        ->and($product['name'])->toBe('Canonical Tuna Sandwich')
        ->and($product['sku'])->toBe('P002-CANONICAL');
});

it('throws request exception when product request fails', function () {
    $productId = 'missing-product';

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with("/v5/products/{$productId}")
        ->once()
        ->andReturn(failedFoodicsResponse(404));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);

    expect(fn () => $service->getProduct($productId))
        ->toThrow(RequestException::class);
});

it('throws runtime exception when successful response misses data', function () {
    $productId = '8d90b8d1';

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with("/v5/products/{$productId}")
        ->once()
        ->andReturn(fakeFoodicsResponse(['product' => ['id' => $productId]]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);

    expect(fn () => $service->getProduct($productId))
        ->toThrow(RuntimeException::class);
});

function fakeFoodicsResponse(array $json): object
{
    return new class($json)
    {
        public function __construct(private array $json) {}

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

        public function body(): string
        {
            return json_encode($this->json);
        }
    };
}

function failedFoodicsResponse(int $status = 404): object
{
    return new class($status)
    {
        public function __construct(private int $status) {}

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

        public function body(): string
        {
            return json_encode(['error' => 'Not found']);
        }
    };
}
