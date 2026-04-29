<?php

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('filters invoices by status', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'PENDING-1', 'status' => 'pending']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'SYNCED-1', 'status' => 'synced']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'FAILED-1', 'status' => 'failed']);

    $this->actingAs($this->user)
        ->get('/invoices?status=pending')
        ->assertOk()
        ->assertSee('PENDING-1')
        ->assertDontSee('SYNCED-1')
        ->assertDontSee('FAILED-1');
});

it('filters invoices by type', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'INV-1', 'type' => InvoiceType::Invoice]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'CN-1', 'type' => InvoiceType::CreditNote]);

    $this->actingAs($this->user)
        ->get('/invoices?type=credit_note')
        ->assertOk()
        ->assertSee('CN-1')
        ->assertDontSee('INV-1');
});

it('filters invoices by amount range', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'CHEAP', 'total_price' => 10.00]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'MID', 'total_price' => 50.00]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'EXPENSIVE', 'total_price' => 500.00]);

    $this->actingAs($this->user)
        ->get('/invoices?amount_from=20&amount_to=100')
        ->assertOk()
        ->assertDontSee('CHEAP')
        ->assertSee('MID')
        ->assertDontSee('EXPENSIVE');
});

it('filters invoices by date range', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'OLD', 'created_at' => '2025-01-15']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'MID', 'created_at' => '2025-06-15']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'NEW', 'created_at' => '2026-01-15']);

    $this->actingAs($this->user)
        ->get('/invoices?date_from=2025-05-01&date_to=2025-12-31')
        ->assertOk()
        ->assertDontSee('OLD')
        ->assertSee('MID')
        ->assertDontSee('NEW');
});

it('searches invoices by foodics reference', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'ORD-999']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'ORD-100']);

    $this->actingAs($this->user)
        ->get('/invoices?search=999')
        ->assertOk()
        ->assertSee('ORD-999')
        ->assertDontSee('ORD-100');
});

it('searches invoices by daftra number', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'FOUND-IT', 'daftra_no' => 'INV-555']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'NOT-HERE', 'daftra_no' => 'INV-999']);

    $this->actingAs($this->user)
        ->get('/invoices?search=555')
        ->assertOk()
        ->assertSee('FOUND-IT')
        ->assertDontSee('NOT-HERE');
});

it('combines multiple filters', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'MATCH', 'status' => 'synced', 'total_price' => 50.00]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'NO-MATCH-STATUS', 'status' => 'pending', 'total_price' => 50.00]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'NO-MATCH-PRICE', 'status' => 'synced', 'total_price' => 500.00]);

    $this->actingAs($this->user)
        ->get('/invoices?status=synced&amount_from=10&amount_to=100')
        ->assertOk()
        ->assertSee('MATCH')
        ->assertDontSee('NO-MATCH-STATUS')
        ->assertDontSee('NO-MATCH-PRICE');
});

it('sorts invoices by foodics reference asc', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'ZZZ']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'AAA']);

    $response = $this->actingAs($this->user)
        ->get('/invoices?sort_by=foodics_reference&sort_dir=asc');

    $response->assertOk();
    $content = $response->getContent();
    $posZzz = strpos($content, 'ZZZ');
    $posAaa = strpos($content, 'AAA');
    expect($posAaa)->toBeLessThan($posZzz);
});

it('sorts invoices by foodics reference desc', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'AAA']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'ZZZ']);

    $response = $this->actingAs($this->user)
        ->get('/invoices?sort_by=foodics_reference&sort_dir=desc');

    $response->assertOk();
    $content = $response->getContent();
    $posZzz = strpos($content, 'ZZZ');
    $posAaa = strpos($content, 'AAA');
    expect($posZzz)->toBeLessThan($posAaa);
});

it('sorts invoices by total price asc', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'EXPENSIVE', 'total_price' => 999.99]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'CHEAP', 'total_price' => 1.00]);

    $response = $this->actingAs($this->user)
        ->get('/invoices?sort_by=total_price&sort_dir=asc');

    $response->assertOk();
    $content = $response->getContent();
    $posCheap = strpos($content, 'CHEAP');
    $posExpensive = strpos($content, 'EXPENSIVE');
    expect($posCheap)->toBeLessThan($posExpensive);
});

it('sorts invoices by created at asc', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'NEWEST', 'created_at' => now()->addDay()]);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'OLDEST', 'created_at' => now()->subDay()]);

    $response = $this->actingAs($this->user)
        ->get('/invoices?sort_by=created_at&sort_dir=asc');

    $response->assertOk();
    $content = $response->getContent();
    $posOldest = strpos($content, 'OLDEST');
    $posNewest = strpos($content, 'NEWEST');
    expect($posOldest)->toBeLessThan($posNewest);
});

it('sorts invoices by status', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'FAILED-ONE', 'status' => 'failed']);
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'SYNCED-ONE', 'status' => 'synced']);

    $this->actingAs($this->user)
        ->get('/invoices?sort_by=status&sort_dir=asc')
        ->assertOk();
});

it('returns all invoices when no filters are applied', function () {
    Invoice::factory()->count(3)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get('/invoices')
        ->assertOk()
        ->assertViewHas('invoices', fn ($invoices) => $invoices->count() === 3);
});

it('rejects invalid sort_by values', function () {
    Invoice::factory()->count(2)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get('/invoices?sort_by=invalid_column')
        ->assertRedirect()
        ->assertSessionHasErrors('sort_by');
});

it('rejects invalid sort_dir values', function () {
    Invoice::factory()->count(2)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get('/invoices?sort_dir=invalid')
        ->assertRedirect()
        ->assertSessionHasErrors('sort_dir');
});

it('rejects invalid status values', function () {
    Invoice::factory()->create(['user_id' => $this->user->id, 'foodics_reference' => 'V-1', 'status' => 'synced']);

    $this->actingAs($this->user)
        ->get('/invoices?status=invalid_status')
        ->assertRedirect()
        ->assertSessionHasErrors('status');
});

it('passes filters to the view', function () {
    $this->actingAs($this->user)
        ->get('/invoices?status=synced&search=foo')
        ->assertOk()
        ->assertViewHas('filters', fn ($filters) => $filters['status'] === 'synced' && $filters['search'] === 'foo');
});

it('rejects amount_to less than amount_from', function () {
    $this->actingAs($this->user)
        ->get('/invoices?amount_from=100&amount_to=50')
        ->assertRedirect()
        ->assertSessionHasErrors('amount_to');
});

it('allows amount_to equal to amount_from', function () {
    $this->actingAs($this->user)
        ->get('/invoices?amount_from=50&amount_to=50')
        ->assertOk();
});

it('allows amount_to without amount_from', function () {
    $this->actingAs($this->user)
        ->get('/invoices?amount_to=100')
        ->assertOk();
});

it('rejects date_to before date_from', function () {
    $this->actingAs($this->user)
        ->get('/invoices?date_from=2026-01-15&date_to=2026-01-10')
        ->assertRedirect()
        ->assertSessionHasErrors('date_to');
});

it('allows date_to equal to date_from', function () {
    $this->actingAs($this->user)
        ->get('/invoices?date_from=2026-01-15&date_to=2026-01-15')
        ->assertOk();
});
