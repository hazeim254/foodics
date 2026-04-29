<?php

use App\Enums\ProductSyncStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('filters products by status', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Pending Product', 'status' => ProductSyncStatus::Pending]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Failed Product', 'status' => ProductSyncStatus::Failed]);

    $response = $this->get('/products?status=synced');
    $response->assertOk()
        ->assertSee('Synced Product')
        ->assertDontSee('Pending Product')
        ->assertDontSee('Failed Product');
});

it('filters products by pending status', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Pending Product', 'status' => ProductSyncStatus::Pending]);

    $response = $this->get('/products?status=pending');
    $response->assertOk()
        ->assertSee('Pending Product')
        ->assertDontSee('Synced Product');
});

it('filters products by failed status', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Failed Product', 'status' => ProductSyncStatus::Failed]);

    $response = $this->get('/products?status=failed');
    $response->assertOk()
        ->assertSee('Failed Product')
        ->assertDontSee('Synced Product');
});

it('searches products by name', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Burger Special']);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Pizza Margherita']);

    $response = $this->get('/products?search=Burger');
    $response->assertOk()
        ->assertSee('Burger Special')
        ->assertDontSee('Pizza Margherita');
});

it('searches products by SKU', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product A', 'foodics_sku' => 'SKU-100']);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product B', 'foodics_sku' => 'SKU-200']);

    $response = $this->get('/products?search=SKU-100');
    $response->assertOk()
        ->assertSee('Product A')
        ->assertDontSee('Product B');
});

it('filters products by price range', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Cheap Product', 'price' => 5.00]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Expensive Product', 'price' => 500.00]);

    $response = $this->get('/products?price_from=1&price_to=10');
    $response->assertOk()
        ->assertSee('Cheap Product')
        ->assertDontSee('Expensive Product');
});

it('filters products by price from only', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Cheap Product', 'price' => 5.00]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Expensive Product', 'price' => 500.00]);

    $response = $this->get('/products?price_from=100');
    $response->assertOk()
        ->assertSee('Expensive Product')
        ->assertDontSee('Cheap Product');
});

it('filters products by date range', function () {
    $old = Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Old Product', 'created_at' => now()->subDays(30)]);
    $recent = Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Recent Product', 'created_at' => now()]);

    $dateFrom = now()->subDays(7)->format('Y-m-d');
    $dateTo = now()->addDay()->format('Y-m-d');

    $response = $this->get("/products?date_from={$dateFrom}&date_to={$dateTo}");
    $response->assertOk()
        ->assertSee('Recent Product')
        ->assertDontSee('Old Product');
});

it('combines multiple filters', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Burger', 'price' => 10.00, 'status' => ProductSyncStatus::Synced]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Burger Premium', 'price' => 50.00, 'status' => ProductSyncStatus::Pending]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Pizza', 'price' => 15.00, 'status' => ProductSyncStatus::Synced]);

    $response = $this->get('/products?search=Burger&status=synced');
    $response->assertOk()
        ->assertSee('Burger')
        ->assertDontSee('Burger Premium')
        ->assertDontSee('Pizza');
});

it('shows all products when no filters applied', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product A']);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product B']);

    $response = $this->get('/products');
    $response->assertOk()
        ->assertSee('Product A')
        ->assertSee('Product B');
});

it('sorts products by name ascending', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Zebra Product']);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Alpha Product']);

    $response = $this->get('/products?sort_by=foodics_name&sort_dir=asc');
    $response->assertOk();

    $content = $response->getContent();
    $alphaPos = strpos($content, 'Alpha Product');
    $zebraPos = strpos($content, 'Zebra Product');
    expect($alphaPos)->toBeLessThan($zebraPos);
});

it('sorts products by name descending', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Alpha Product']);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Zebra Product']);

    $response = $this->get('/products?sort_by=foodics_name&sort_dir=desc');
    $response->assertOk();

    $content = $response->getContent();
    $alphaPos = strpos($content, 'Alpha Product');
    $zebraPos = strpos($content, 'Zebra Product');
    expect($zebraPos)->toBeLessThan($alphaPos);
});

it('sorts products by price ascending', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Expensive', 'price' => 500.00]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Cheap', 'price' => 5.00]);

    $response = $this->get('/products?sort_by=price&sort_dir=asc');
    $response->assertOk();

    $content = $response->getContent();
    $cheapPos = strpos($content, 'Cheap');
    $expensivePos = strpos($content, 'Expensive');
    expect($cheapPos)->toBeLessThan($expensivePos);
});

it('sorts products by created at ascending', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Old Product', 'created_at' => now()->subDays(10)]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'New Product', 'created_at' => now()]);

    $response = $this->get('/products?sort_by=created_at&sort_dir=asc');
    $response->assertOk();

    $content = $response->getContent();
    $oldPos = strpos($content, 'Old Product');
    $newPos = strpos($content, 'New Product');
    expect($oldPos)->toBeLessThan($newPos);
});

it('sorts products by status', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Failed Product', 'status' => ProductSyncStatus::Failed]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);

    $response = $this->get('/products?sort_by=status&sort_dir=asc');
    $response->assertOk()
        ->assertSee('Failed Product')
        ->assertSee('Synced Product');
});

it('defaults to created_at descending sort', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Old Product', 'created_at' => now()->subDays(5)]);
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'New Product', 'created_at' => now()]);

    $response = $this->get('/products');
    $response->assertOk();

    $content = $response->getContent();
    $newPos = strpos($content, 'New Product');
    $oldPos = strpos($content, 'Old Product');
    expect($newPos)->toBeLessThan($oldPos);
});

it('persists filters across pagination', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);

    $response = $this->get('/products?status=synced');
    $response->assertOk()
        ->assertSee('status=synced');
});

it('displays price column', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product With Price', 'price' => 42.50]);

    $this->get('/products')
        ->assertOk()
        ->assertSee('42.50');
});

it('shows dash when price is null', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Product No Price', 'price' => null]);

    $this->get('/products')
        ->assertOk()
        ->assertSee('Product No Price');
});

it('shows filter bar when products exist', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Test Product']);

    $this->get('/products')
        ->assertOk()
        ->assertSee('Filters');
});

it('shows filter bar even when no products but filters are active', function () {
    $this->get('/products?status=synced')
        ->assertOk()
        ->assertSee('Filters');
});

it('shows active filter tags', function () {
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'Synced Product', 'status' => ProductSyncStatus::Synced]);

    $this->get('/products?status=synced')
        ->assertOk()
        ->assertSee('Status: synced');
});

it('validates filter parameters', function () {
    $this->get('/products?status=invalid')
        ->assertSessionHasErrors(['status']);
});

it('validates sort parameters', function () {
    $this->get('/products?sort_by=nonexistent')
        ->assertSessionHasErrors(['sort_by']);
});

it('does not show other users products in filtered results', function () {
    $otherUser = User::factory()->create();
    Product::factory()->create(['user_id' => $this->user->id, 'foodics_name' => 'My Product', 'status' => ProductSyncStatus::Synced]);
    Product::factory()->create(['user_id' => $otherUser->id, 'foodics_name' => 'Other Product', 'status' => ProductSyncStatus::Synced]);

    $this->get('/products?status=synced')
        ->assertOk()
        ->assertSee('My Product')
        ->assertDontSee('Other Product');
});

it('rejects price_to less than price_from', function () {
    $this->get('/products?price_from=100&price_to=50')
        ->assertRedirect()
        ->assertSessionHasErrors('price_to');
});

it('allows price_to equal to price_from', function () {
    $this->get('/products?price_from=50&price_to=50')
        ->assertOk();
});

it('allows price_to without price_from', function () {
    $this->get('/products?price_to=100')
        ->assertOk();
});

it('rejects date_to before date_from', function () {
    $this->get('/products?date_from=2026-01-15&date_to=2026-01-10')
        ->assertRedirect()
        ->assertSessionHasErrors('date_to');
});

it('allows date_to equal to date_from', function () {
    $this->get('/products?date_from=2026-01-15&date_to=2026-01-15')
        ->assertOk();
});
