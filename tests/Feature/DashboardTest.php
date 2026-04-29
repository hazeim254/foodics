<?php

use App\Enums\InvoiceSyncStatus;
use App\Enums\ProductSyncStatus;
use App\Enums\SettingKey;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows the dashboard view to authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertViewIs('dashboard');
});

it('shows invoice counts for synced, failed, and pending statuses', function () {
    $user = User::factory()->create();
    Invoice::factory()->count(3)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Synced]);
    Invoice::factory()->count(2)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Failed]);
    Invoice::factory()->count(1)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Pending]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $invoiceStats = $response->viewData('invoiceStats');

    expect($invoiceStats['synced'])->toBe(3);
    expect($invoiceStats['failed'])->toBe(2);
    expect($invoiceStats['pending'])->toBe(1);
});

it('shows product counts for synced, failed, and pending statuses', function () {
    $user = User::factory()->create();
    Product::factory()->count(4)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Synced]);
    Product::factory()->count(1)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Failed]);
    Product::factory()->count(2)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Pending]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $productStats = $response->viewData('productStats');

    expect($productStats['synced'])->toBe(4);
    expect($productStats['failed'])->toBe(1);
    expect($productStats['pending'])->toBe(2);
});

it('shows total invoice and product counts', function () {
    $user = User::factory()->create();
    Invoice::factory()->count(5)->create(['user_id' => $user->id]);
    Product::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $invoiceStats = $response->viewData('invoiceStats');
    $productStats = $response->viewData('productStats');

    expect($invoiceStats['total'])->toBe(5);
    expect($productStats['total'])->toBe(3);
});

it('shows invoice and product success rates', function () {
    $user = User::factory()->create();
    Invoice::factory()->count(3)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Synced]);
    Invoice::factory()->count(1)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Failed]);
    Product::factory()->count(2)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Synced]);
    Product::factory()->count(2)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Failed]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $invoiceStats = $response->viewData('invoiceStats');
    $productStats = $response->viewData('productStats');

    expect($invoiceStats['success_rate'])->toBe(75.0);
    expect($productStats['success_rate'])->toBe(50.0);
});

it('shows zero percent success rate when user has no records', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $invoiceStats = $response->viewData('invoiceStats');
    $productStats = $response->viewData('productStats');

    expect($invoiceStats['success_rate'])->toBe(0);
    expect($productStats['success_rate'])->toBe(0);
});

it('shows sync over time chart data for the authenticated user', function () {
    $user = User::factory()->create();
    Invoice::factory()->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Synced, 'created_at' => now()]);
    Product::factory()->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Failed, 'created_at' => now()]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $syncOverTime = $response->viewData('syncOverTime');

    expect($syncOverTime)->toHaveKeys(['labels', 'invoices', 'products']);
    expect($syncOverTime['labels'])->toHaveCount(7);
    expect($syncOverTime['invoices']['synced'])->toHaveCount(7);
    expect($syncOverTime['invoices']['failed'])->toHaveCount(7);
    expect($syncOverTime['products']['synced'])->toHaveCount(7);
    expect($syncOverTime['products']['failed'])->toHaveCount(7);

    expect($syncOverTime['invoices']['synced'][6])->toBe(1);
    expect($syncOverTime['invoices']['failed'][6])->toBe(0);
    expect($syncOverTime['products']['failed'][6])->toBe(1);
    expect($syncOverTime['products']['synced'][6])->toBe(0);
});

it('only counts records belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Invoice::factory()->count(3)->create(['user_id' => $user->id, 'status' => InvoiceSyncStatus::Synced]);
    Invoice::factory()->count(5)->create(['user_id' => $otherUser->id, 'status' => InvoiceSyncStatus::Synced]);

    Product::factory()->count(2)->create(['user_id' => $user->id, 'status' => ProductSyncStatus::Synced]);
    Product::factory()->count(4)->create(['user_id' => $otherUser->id, 'status' => ProductSyncStatus::Synced]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $invoiceStats = $response->viewData('invoiceStats');
    $productStats = $response->viewData('productStats');

    expect($invoiceStats['total'])->toBe(3);
    expect($invoiceStats['synced'])->toBe(3);
    expect($productStats['total'])->toBe(2);
    expect($productStats['synced'])->toBe(2);
});

it('shows configured default client and default branch', function () {
    $user = User::factory()->create();
    $user->setSetting(SettingKey::DaftraDefaultClientId, '42');
    $user->setSetting(SettingKey::DaftraDefaultBranchId, '5');

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('42')
        ->assertSee('5');
});

it('shows fallback text when default client and branch are not configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Not configured')
        ->assertSee('Default branch (1)');
});

it('includes a link to the settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee(route('settings'));
});
