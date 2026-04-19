<?php

use App\Enums\ProductSyncStatus;
use App\Jobs\SyncProductsJob;
use App\Models\Product;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

function mockProductHttpResponse(array $json, bool $successful = true, int $status = 200): object
{
    return new class($successful, $status, $json)
    {
        public function __construct(
            private bool $successful,
            private int $status,
            private array $json,
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

        public function json($key = null, $default = null): mixed
        {
            if ($key === null) {
                return $this->json;
            }

            return data_get($this->json, $key, $default);
        }

        public function throw(): static
        {
            return $this;
        }

        public function body(): string
        {
            return json_encode($this->json);
        }
    };
}

function createUserWithFoodicsToken(): User
{
    $user = User::factory()->create();
    ProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'foodics',
        'token' => 'foodics-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addYear(),
    ]);

    return $user;
}

it('syncs products from Foodics and creates local rows', function () {
    $user = createUserWithFoodicsToken();
    Context::add('user', $user);

    $mockProducts = [
        ['id' => 'foodics-prod-1', 'name' => 'Product One', 'sku' => 'SKU001', 'price' => 10.00, 'is_active' => true],
        ['id' => 'foodics-prod-2', 'name' => 'Product Two', 'sku' => 'SKU002', 'price' => 20.00, 'is_active' => true],
    ];

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/v5/products', Mockery::any())
        ->andReturn(mockProductHttpResponse(['data' => $mockProducts, 'meta' => ['next_cursor' => null]]));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $daftraClient = Mockery::mock(DaftraApiClient::class);
    $daftraClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockProductHttpResponse(['data' => []]));
    $daftraClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockProductHttpResponse(['id' => 12345]));
    $this->app->instance(DaftraApiClient::class, $daftraClient);

    $job = new SyncProductsJob($user);
    $job->handle();

    expect(Product::count())->toBe(2);
    expect(Product::where('foodics_id', 'foodics-prod-1')->first()->foodics_name)->toBe('Product One');
    expect(Product::where('foodics_id', 'foodics-prod-2')->first()->foodics_name)->toBe('Product Two');
});

it('skips products that are already synced', function () {
    $user = createUserWithFoodicsToken();
    Context::add('user', $user);

    Product::factory()->create([
        'user_id' => $user->id,
        'foodics_id' => 'existing-prod',
        'daftra_id' => 999,
        'foodics_name' => 'Existing Product',
        'status' => ProductSyncStatus::Synced,
    ]);

    $mockProducts = [
        ['id' => 'existing-prod', 'name' => 'Updated Product', 'sku' => 'UPDATED', 'price' => 15.00, 'is_active' => true],
    ];

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/v5/products', Mockery::any())
        ->andReturn(mockProductHttpResponse(['data' => $mockProducts, 'meta' => ['next_cursor' => null]]));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $daftraClient = Mockery::mock(DaftraApiClient::class);
    $daftraClient->shouldNotReceive('get');
    $daftraClient->shouldNotReceive('post');
    $this->app->instance(DaftraApiClient::class, $daftraClient);

    $job = new SyncProductsJob($user);
    $job->handle();

    expect(Product::count())->toBe(1);
    expect(Product::first()->foodics_name)->not->toBe('Updated Product');
});

it('clears cache key in finally block even when exception occurs', function () {
    $user = createUserWithFoodicsToken();
    Context::add('user', $user);

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/v5/products', Mockery::any())
        ->andThrow(new Exception('API Error'));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $job = new SyncProductsJob($user);

    try {
        $job->handle();
    } catch (Exception $e) {
    }

    expect(Cache::has('sync_products_in_progress:'.$user->id))->toBeFalse();
});

it('returns gracefully when user has no Foodics token', function () {
    $user = User::factory()->create(['foodics_meta' => null]);

    $job = new SyncProductsJob($user);
    $job->handle();

    expect(Product::count())->toBe(0);
});

it('is ShouldBeUnique per user', function () {
    $user = User::factory()->create();

    $job = new SyncProductsJob($user);

    expect($job->uniqueId())->toBe((string) $user->id);
    expect($job->uniqueFor)->toBe(300);
});

it('sets product to Failed when Daftra sync throws', function () {
    $user = createUserWithFoodicsToken();
    Context::add('user', $user);

    $mockProducts = [
        ['id' => 'fail-prod', 'name' => 'Fail Product', 'sku' => 'FAIL', 'price' => 10.00, 'is_active' => true],
    ];

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/v5/products', Mockery::any())
        ->andReturn(mockProductHttpResponse(['data' => $mockProducts, 'meta' => ['next_cursor' => null]]));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $daftraClient = Mockery::mock(DaftraApiClient::class);
    $daftraClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andThrow(new RuntimeException('Daftra Error'));
    $this->app->instance(DaftraApiClient::class, $daftraClient);

    $job = new SyncProductsJob($user);
    $job->handle();

    $product = Product::where('foodics_id', 'fail-prod')->first();
    expect($product)->not->toBeNull();
    expect($product->status)->toBe(ProductSyncStatus::Failed);
});
