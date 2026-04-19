<?php

use App\Enums\ProductSyncStatus;
use App\Jobs\RetryProductSyncJob;
use App\Jobs\SyncProductsJob;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from products to login', function () {
    $this->get('/products')->assertRedirect('/login');
});

it('redirects unauthenticated users from product sync to login', function () {
    $this->post('/products/sync')->assertRedirect('/login');
});

it('displays products for authenticated user', function () {
    $user = User::factory()->create();
    Product::factory()->create([
        'user_id' => $user->id,
        'foodics_id' => 'prod-001',
        'foodics_name' => 'Test Product',
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertViewIs('products')
        ->assertSee('Test Product')
        ->assertSee('Synced');
});

it('shows empty state when no products exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('No products yet.')
        ->assertSee('Sync your Foodics products to see them here.');
});

it('only shows products belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Product::factory()->create(['user_id' => $user->id, 'foodics_name' => 'My Product']);
    Product::factory()->create(['user_id' => $otherUser->id, 'foodics_name' => 'Other Product']);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('My Product')
        ->assertDontSee('Other Product');
});

it('dispatches sync job and redirects with status message', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/products/sync')
        ->assertRedirect(route('products'));

    Bus::assertDispatched(SyncProductsJob::class);
    $this->assertStringContainsString('Product sync started.', session('status'));
});

it('prevents duplicate sync when one is already in progress', function () {
    Bus::fake();

    $user = User::factory()->create();
    Cache::put('sync_products_in_progress:'.$user->id, true, now()->addMinutes(5));

    $this->actingAs($user)
        ->post('/products/sync')
        ->assertRedirect(route('products'));

    Bus::assertNotDispatched(SyncProductsJob::class);
    $this->assertStringContainsString('Product sync is already in progress.', session('status'));
});

it('sets cache key when dispatching sync job', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->post('/products/sync');

    $this->assertTrue(Cache::has('sync_products_in_progress:'.$user->id));
});

it('returns correct syncing status as JSON', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/products/sync-status')
        ->assertOk()
        ->assertJson(['syncing' => false]);
});

it('returns syncing true when cache key exists', function () {
    $user = User::factory()->create();
    Cache::put('sync_products_in_progress:'.$user->id, true, now()->addMinutes(5));

    $this->actingAs($user)
        ->getJson('/products/sync-status')
        ->assertOk()
        ->assertJson(['syncing' => true]);
});

it('requires authentication for sync-status endpoint', function () {
    $this->getJson('/products/sync-status')->assertUnauthorized();
});

it('aborts 403 when resyncing another users product', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $otherUser->id, 'status' => ProductSyncStatus::Failed]);

    $this->actingAs($user)
        ->post("/products/{$product->id}/resync")
        ->assertForbidden();
});

it('redirects back with error when product is already synced', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Already Synced Product',
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->post("/products/{$product->id}/resync")
        ->assertRedirect(route('products'));

    $this->assertStringContainsString('already synced', session('status'));
});

it('dispatches RetryProductSyncJob for a pending product', function () {
    Bus::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Pending Product',
        'status' => ProductSyncStatus::Pending,
    ]);

    $this->actingAs($user)
        ->post("/products/{$product->id}/resync")
        ->assertRedirect(route('products'));

    Bus::assertDispatched(RetryProductSyncJob::class);
});

it('dispatches RetryProductSyncJob for a failed product', function () {
    Bus::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Failed Product',
        'status' => ProductSyncStatus::Failed,
    ]);

    $this->actingAs($user)
        ->post("/products/{$product->id}/resync")
        ->assertRedirect(route('products'));

    Bus::assertDispatched(RetryProductSyncJob::class);
});

it('shows Resync button for pending products', function () {
    $user = User::factory()->create();
    Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Pending Product',
        'status' => ProductSyncStatus::Pending,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('Resync');
});

it('shows Resync button for failed products', function () {
    $user = User::factory()->create();
    Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Failed Product',
        'status' => ProductSyncStatus::Failed,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('Resync');
});

it('does not show Resync button for synced products', function () {
    $user = User::factory()->create();
    Product::factory()->create([
        'user_id' => $user->id,
        'foodics_name' => 'Synced Product',
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertDontSee('Resync');
});

it('shows Sync Now button when not syncing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('Sync Now');
});

it('shows Syncing indicator when syncing', function () {
    $user = User::factory()->create();
    Cache::put('sync_products_in_progress:'.$user->id, true, now()->addMinutes(5));

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('Syncing');
});

it('displays product name as clickable link', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_id' => 'prod-abc-123',
        'foodics_name' => 'My Product',
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('<a href="'.config('services.foodics.base_url').'/menu/products/prod-abc-123" target="_blank"', false)
        ->assertSee('My Product');
});

it('displays daftra id as clickable link with subdomain', function () {
    $user = User::factory()->create([
        'daftra_meta' => ['subdomain' => 'myshop.daftra.com'],
    ]);
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'daftra_id' => 12345,
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('<a href="https://myshop.daftra.com/owner/products/view/12345" target="_blank"', false)
        ->assertSee('12345');
});

it('displays dash when foodics_sku is null', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_sku' => null,
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('—');
});

it('displays product SKU when present', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'foodics_sku' => 'SKU-12345',
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('SKU-12345');
});

it('displays Pending badge with correct styling', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'status' => ProductSyncStatus::Pending,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('bg-amber-50')
        ->assertSee('Pending');
});

it('displays Failed badge with correct styling', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'status' => ProductSyncStatus::Failed,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('bg-red-50')
        ->assertSee('Failed');
});

it('displays Synced badge with correct styling', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'status' => ProductSyncStatus::Synced,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('bg-green-50')
        ->assertSee('Synced');
});
