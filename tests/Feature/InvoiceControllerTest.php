<?php

use App\Jobs\SyncInvoicesJob;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from invoices to login', function () {
    $this->get('/invoices')->assertRedirect('/login');
});

it('displays invoices for authenticated user', function () {
    $user = User::factory()->create();
    Invoice::factory()->create(['user_id' => $user->id, 'foodics_reference' => 'ORD-001', 'status' => 'synced']);

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertViewIs('invoices')
        ->assertSee('ORD-001')
        ->assertSee('Synced');
});

it('shows empty state when no invoices exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('No invoices yet.')
        ->assertSee('Sync your Foodics orders to see them here.');
});

it('only shows invoices belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Invoice::factory()->create(['user_id' => $user->id, 'foodics_reference' => 'MY-ORDER']);
    Invoice::factory()->create(['user_id' => $otherUser->id, 'foodics_reference' => 'OTHER-ORDER']);

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('MY-ORDER')
        ->assertDontSee('OTHER-ORDER');
});

it('passes syncing state to view when cache key exists', function () {
    $user = User::factory()->create();
    Cache::put('sync_in_progress:'.$user->id, true, now()->addMinutes(5));

    $response = $this->actingAs($user)->get('/invoices');

    $response->assertOk();
    $response->assertViewHas('syncing', true);
});

it('passes syncing false state when no cache key exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/invoices');

    $response->assertOk();
    $response->assertViewHas('syncing', false);
});

it('displays flash status message', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['status' => 'Sync started.'])
        ->get('/invoices')
        ->assertOk()
        ->assertSee('Sync started.');
});

it('dispatches sync job and redirects with status message', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/invoices/sync')
        ->assertRedirect(route('invoices'));

    Bus::assertDispatched(SyncInvoicesJob::class);
    $this->assertStringContainsString('Sync started.', session('status'));
});

it('prevents duplicate sync when one is already in progress', function () {
    Bus::fake();

    $user = User::factory()->create();
    Cache::put('sync_in_progress:'.$user->id, true, now()->addMinutes(5));

    $this->actingAs($user)
        ->post('/invoices/sync')
        ->assertRedirect(route('invoices'));

    Bus::assertNotDispatched(SyncInvoicesJob::class);
    $this->assertStringContainsString('Sync is already in progress.', session('status'));
});

it('sets cache key when dispatching sync job', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->post('/invoices/sync');

    $this->assertTrue(Cache::has('sync_in_progress:'.$user->id));
});

it('requires authentication for sync endpoint', function () {
    $this->post('/invoices/sync')->assertRedirect('/login');
});

it('returns correct syncing status as JSON', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/invoices/sync-status')
        ->assertOk()
        ->assertJson(['syncing' => false]);
});

it('returns syncing true when cache key exists', function () {
    $user = User::factory()->create();
    Cache::put('sync_in_progress:'.$user->id, true, now()->addMinutes(5));

    $this->actingAs($user)
        ->getJson('/invoices/sync-status')
        ->assertOk()
        ->assertJson(['syncing' => true]);
});

it('requires authentication for sync-status endpoint', function () {
    $this->getJson('/invoices/sync-status')->assertUnauthorized();
});
