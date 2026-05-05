<?php

use App\Models\EntityMapping;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\PaymentMethodService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use App\Services\SyncCreditNote;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('resolves branch mapping from entity_mappings for an order', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'foodics-branch-1',
        'daftra_id' => 5,
        'status' => 'synced',
    ]);

    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('setBranchOverride')->with(5)->once();
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(100);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);
    $mockInvoiceService->shouldReceive('clearBranchOverride')->once();

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-1',
        'reference' => '00100',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 100,
        'branch' => ['id' => 'foodics-branch-1', 'name' => 'Branch 1'],
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-1')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->daftra_id)->toBe(100);
    expect($invoice->foodics_metadata['branch_id'])->toBe('foodics-branch-1');
});

it('falls back to default branch when no branch mapping exists', function () {
    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(200);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);
    $mockInvoiceService->shouldReceive('clearBranchOverride')->once();

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-2',
        'reference' => '00200',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 50,
        'branch' => ['id' => 'unmapped-branch', 'name' => 'Unmapped'],
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-2')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->daftra_id)->toBe(200);
    expect($invoice->foodics_metadata['branch_id'])->toBe('unmapped-branch');
});

it('handles orders without branch data', function () {
    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(300);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);
    $mockInvoiceService->shouldReceive('clearBranchOverride')->once();

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-3',
        'reference' => '00300',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 75,
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-3')->first();
    expect($invoice)->not->toBeNull();
});