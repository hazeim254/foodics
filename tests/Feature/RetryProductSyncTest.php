<?php

use App\Enums\ProductSyncStatus;
use App\Jobs\RetryProductSyncJob;
use App\Models\Product;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

function mockRetryProductHttpResponse(array $json, bool $successful = true, int $status = 200): object
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

function createRetryTestUser(): User
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

it('re-syncs a single product from Foodics', function () {
    $user = createRetryTestUser();
    Context::add('user', $user);

    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_id' => 'prod-to-resync',
        'foodics_name' => 'Original Name',
        'daftra_id' => null,
        'status' => ProductSyncStatus::Failed,
    ]);

    $mockFoodicsProduct = [
        'id' => 'prod-to-resync',
        'name' => 'Updated Name',
        'sku' => 'NEW-SKU',
        'price' => 25.00,
        'is_active' => true,
    ];

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/v5/products/prod-to-resync')
        ->andReturn(mockRetryProductHttpResponse(['data' => $mockFoodicsProduct]));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $daftraClient = Mockery::mock(DaftraApiClient::class);
    $daftraClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockRetryProductHttpResponse(['data' => []]));
    $daftraClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockRetryProductHttpResponse(['id' => 789]));
    $this->app->instance(DaftraApiClient::class, $daftraClient);

    $job = new RetryProductSyncJob($product);
    $job->handle();

    $product->refresh();
    expect($product->status)->toBe(ProductSyncStatus::Synced);
    expect($product->daftra_id)->toBe(789);
    expect($product->foodics_name)->toBe('Updated Name');
});

it('sets product to Failed when user has no Foodics token', function () {
    $user = User::factory()->create(['foodics_meta' => null]);

    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_id' => 'prod-no-token',
        'status' => ProductSyncStatus::Pending,
    ]);

    $job = new RetryProductSyncJob($product);
    $job->handle();

    $product->refresh();
    expect($product->status)->toBe(ProductSyncStatus::Failed);
});

it('has tries set to 3', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $job = new RetryProductSyncJob($product);

    expect($job->tries)->toBe(3);
});
