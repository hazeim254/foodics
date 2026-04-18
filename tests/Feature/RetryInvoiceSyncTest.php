<?php

use App\Enums\InvoiceSyncStatus;
use App\Jobs\RetryInvoiceSyncJob;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from retry-sync to login', function () {
    $invoice = Invoice::factory()->create(['status' => 'failed']);

    $this->post("/invoices/{$invoice->id}/retry-sync")->assertRedirect('/login');
});

it('aborts 403 when retrying another users invoice', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $otherUser->id, 'status' => 'failed']);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/retry-sync")
        ->assertForbidden();
});

it('redirects back with error when invoice is already synced', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'synced']);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/retry-sync")
        ->assertRedirect(route('invoices'));

    $this->assertStringContainsString('already synced', session('status'));
});

it('dispatches RetryInvoiceSyncJob for a failed invoice', function () {
    Bus::fake();

    $user = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'foodics_reference' => 'ORD-RETRY',
        'status' => 'failed',
    ]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/retry-sync")
        ->assertRedirect(route('invoices'));

    Bus::assertDispatched(RetryInvoiceSyncJob::class);
    $this->assertStringContainsString('ORD-RETRY', session('status'));
});

it('dispatches RetryInvoiceSyncJob for a pending invoice', function () {
    Bus::fake();

    $user = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'foodics_reference' => 'ORD-PENDING',
        'status' => 'pending',
        'daftra_id' => null,
    ]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/retry-sync")
        ->assertRedirect(route('invoices'));

    Bus::assertDispatched(RetryInvoiceSyncJob::class);
});

it('resets invoice status to failed before dispatching job', function () {
    Bus::fake();

    $user = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'daftra_id' => null,
    ]);

    $this->actingAs($user)->post("/invoices/{$invoice->id}/retry-sync");

    expect($invoice->fresh()->status)->toBe(InvoiceSyncStatus::Failed);
});

it('shows retry button for pending invoices', function () {
    $user = User::factory()->create();
    Invoice::factory()->create([
        'user_id' => $user->id,
        'foodics_reference' => 'ORD-PEND',
        'status' => 'pending',
        'daftra_id' => null,
    ]);

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('Retry Sync');
});

it('shows retry button for failed invoices', function () {
    $user = User::factory()->create();
    Invoice::factory()->create([
        'user_id' => $user->id,
        'foodics_reference' => 'ORD-FAIL',
        'status' => 'failed',
    ]);

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('Retry Sync');
});

it('does not show retry button for synced invoices', function () {
    $user = User::factory()->create();
    Invoice::factory()->create([
        'user_id' => $user->id,
        'foodics_reference' => 'ORD-SYNCED',
        'status' => 'synced',
    ]);

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertDontSee('Retry Sync');
});
