<?php

use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('finds product by sku without id fallback lookup', function () {
    $foodicsProduct = [
        'id' => 'foodics-product-id',
        'sku' => 'SKU-100',
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'SKU-100'])
        ->once()
        ->andReturn(fakeDaftraResponse([
            'data' => [
                ['Product' => ['id' => 1122]],
            ],
        ]));
    $mockClient->shouldNotReceive('get')
        ->with('/api2/products', ['product_code' => 'foodics-product-id']);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);
    $productId = $service->getProduct($foodicsProduct);

    expect($productId)->toBe(1122);
});

it('falls back to foodics id when sku lookup misses', function () {
    $foodicsProduct = [
        'id' => 'foodics-product-id',
        'sku' => 'SKU-100',
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'SKU-100'])
        ->once()
        ->andReturn(fakeDaftraResponse(['data' => []]));
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'foodics-product-id'])
        ->once()
        ->andReturn(fakeDaftraResponse([
            'data' => [
                ['Product' => ['id' => 3344]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);
    $productId = $service->getProduct($foodicsProduct);

    expect($productId)->toBe(3344);
});

it('creates product with mapped foodics fields', function () {
    $foodicsProduct = [
        'id' => 'foodics-product-id',
        'name' => 'Foodics Name',
        'description' => 'Foodics Description',
        'sku' => 'SKU-200',
        'barcode' => 'BC-123',
        'price' => 25.5,
        'cost' => 10.75,
        'is_active' => false,
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('Product');
            expect($payload['Product']['name'])->toBe('Foodics Name');
            expect($payload['Product']['description'])->toBe('Foodics Description');
            expect($payload['Product']['product_code'])->toBe('SKU-200');
            expect($payload['Product']['barcode'])->toBe('BC-123');
            expect($payload['Product']['unit_price'])->toBe(25.5);
            expect($payload['Product']['buy_price'])->toBe(10.75);
            expect($payload['Product']['status'])->toBe(1);

            return true;
        }))
        ->once()
        ->andReturn(fakeDaftraResponse(['id' => 7788], successful: true, status: 201));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(ProductService::class);
    $productId = $service->createProduct($foodicsProduct);

    expect($productId)->toBe(7788);
});

function fakeDaftraResponse(array $json, bool $successful = true, int $status = 200): object
{
    return new class($json, $successful, $status)
    {
        public function __construct(
            private array $json,
            private bool $successful,
            private int $status,
        ) {}

        public function successful(): bool
        {
            return $this->successful;
        }

        public function failed(): bool
        {
            return ! $this->successful;
        }

        public function status(): int
        {
            return $this->status;
        }

        public function body(): string
        {
            return json_encode($this->json);
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
