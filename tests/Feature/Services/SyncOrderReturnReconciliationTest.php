<?php

use App\Dtos\Reconciliation\SalesReconciliationDifference;
use App\Dtos\Reconciliation\SalesReconciliationResult;
use App\Dtos\Reconciliation\SalesReconciliationSummary;
use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use App\Enums\SalesReconciliationStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Reconciliation\SalesReconciliationService;
use App\Services\SyncOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->originalFoodicsId = 'original-order-recon-001';
    $this->returnFoodicsId = 'return-order-recon-001';

    $this->original = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->originalFoodicsId,
        'foodics_reference' => '00200',
        'daftra_id' => 12345,
        'status' => InvoiceSyncStatus::Synced,
        'daftra_no' => 'INV-001',
        'daftra_metadata' => ['client_id' => 42],
    ]);
});

function makeReturnOrderForRecon(): array
{
    return [
        'id' => 'return-order-recon-001',
        'reference' => '00300',
        'status' => 5,
        'business_date' => '2026-04-15',
        'discount_amount' => 0,
        'kitchen_notes' => 'Return note',
        'total_price' => 10.0,
        'subtotal_price' => 10.0,
        'original_order' => ['id' => 'original-order-recon-001', 'reference' => '00200'],
        'customer' => null,
        'products' => [
            [
                'id' => 'p1',
                'quantity' => 1,
                'unit_price' => 10,
                'total_price' => 10,
                'discount_amount' => 0,
                'discount_type' => 2,
                'product' => ['id' => 'p1', 'name' => 'Test Product', 'sku' => '', 'price' => 10, 'cost' => null, 'is_active' => true, 'description' => '', 'barcode' => null],
                'taxes' => [],
                'options' => [],
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];
}

it('stores reconciliation metadata on the local credit-note row after returned order sync', function () {
    $returnOrder = makeReturnOrderForRecon();

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 10.0,
                productTotal: 10.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 0.0,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 0.0,
                expectedTotal: 10.0,
                status: SalesReconciliationStatus::Ok,
                type: 'credit_note',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();

    expect($creditNote)->not->toBeNull();
    expect($creditNote->type)->toBe(InvoiceType::CreditNote);
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->foodics_metadata)->toHaveKey('sales_reconciliation');

    $recon = $creditNote->foodics_metadata['sales_reconciliation'];
    expect($recon)->toHaveKey('status');
    expect($recon)->toHaveKey('summary');
    expect($recon)->toHaveKey('differences');
    expect($recon['summary']['type'])->toBe('credit_note');
});

it('flags return-order payments as a known gap in reconciliation', function () {
    $returnOrder = makeReturnOrderForRecon();
    $returnOrder['payments'] = [
        ['amount' => 10.0, 'tips' => 0],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::KnownGap,
            summary: new SalesReconciliationSummary(
                subtotal: 10.0,
                productTotal: 10.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 0.0,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 10.0,
                expectedTotal: 10.0,
                status: SalesReconciliationStatus::KnownGap,
                type: 'credit_note',
                differences: [],
            ),
            differences: [new SalesReconciliationDifference(
                component: 'return_payments',
                foodicsAmount: 10.0,
                daftraAmount: 0.0,
                delta: 10.0,
                severity: 'known_gap',
                explanation: 'Return order payments are not yet synced',
            )],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    Log::shouldReceive('warning')->once()->withArgs(function (string $message) {
        return str_contains($message, 'Return order carries payments');
    });

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    $recon = $creditNote->foodics_metadata['sales_reconciliation'];

    $returnPaymentDiff = collect($recon['differences'])->first(fn ($d) => $d['component'] === 'return_payments');
    expect($returnPaymentDiff)->not->toBeNull()
        ->and($returnPaymentDiff['severity'])->toBe('known_gap');
});

it('logs mismatch warning for credit-note reconciliation drift', function () {
    $returnOrder = makeReturnOrderForRecon();

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Mismatch,
            summary: new SalesReconciliationSummary(
                subtotal: 10.0,
                productTotal: 10.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 0.0,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 0.0,
                expectedTotal: 10.0,
                status: SalesReconciliationStatus::Mismatch,
                type: 'credit_note',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'Sales reconciliation mismatch')
            && isset($context['order_id'])
            && $context['invoice_type'] === 'credit_note';
    });

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote->foodics_metadata['sales_reconciliation']['status'])->toBe('mismatch');
});

it('does not prevent credit-note sync when reconciliation fails', function () {
    $returnOrder = makeReturnOrderForRecon();

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andThrow(new RuntimeException('Reconciliation exploded'));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'Reconciliation failed after sync')
            && isset($context['order_id']);
    });

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
});

it('fetches credit-note document via getCreditNoteById and passes it to reconciliation', function () {
    $returnOrder = makeReturnOrderForRecon();

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $order, array $payload, ?array $document) {
            expect($payload)->toHaveKey('CreditNote')
                ->and($payload['CreditNote'])->toHaveKey('discount_amount')
                ->and($document)->not->toBeNull()
                ->and($document['id'])->toBe(55555)
                ->and($document['total'])->toBe(10.0);

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 10.0,
                productTotal: 10.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 0.0,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 0.0,
                expectedTotal: 10.0,
                status: SalesReconciliationStatus::Ok,
                type: 'credit_note',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote)->not->toBeNull();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->foodics_metadata)->toHaveKey('sales_reconciliation');
});

it('uses credit-note document total to reconcile taxable returned order', function () {
    $returnOrder = makeReturnOrderForRecon();
    $returnOrder['total_price'] = 57.50;
    $returnOrder['subtotal_price'] = 50.0;
    $returnOrder['products'][0]['total_price'] = 50.0;
    $returnOrder['products'][0]['unit_price'] = 50.0;
    $returnOrder['products'][0]['taxes'] = [['id' => 't1', 'pivot' => ['amount' => 7.5]]];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $order, array $payload, ?array $document) {
            expect($document)->not->toBeNull()
                ->and($document['total'])->toBe(57.5);

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 50.0,
                productTotal: 50.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 7.5,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 0.0,
                expectedTotal: 57.5,
                status: SalesReconciliationStatus::Ok,
                type: 'credit_note',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 54321]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 57.5]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote)->not->toBeNull();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->foodics_metadata['sales_reconciliation']['status'])->toBe('ok');
});

it('does not rebuild credit-note payload during reconciliation persistence', function () {
    $returnOrder = makeReturnOrderForRecon();

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $order, array $payload, ?array $document) {
            expect($payload)->toHaveKey('CreditNote')
                ->and($payload['CreditNote'])->toHaveKey('discount_amount')
                ->and($document)->not->toBeNull();

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 10.0,
                productTotal: 10.0,
                optionTotal: 0.0,
                comboProductTotal: 0.0,
                chargeTotal: 0.0,
                productDiscountTotal: 0.0,
                optionDiscountTotal: 0.0,
                comboDiscountTotal: 0.0,
                orderDiscount: 0.0,
                taxTotal: 0.0,
                tipTotal: 0.0,
                roundingAmount: 0.0,
                paymentTotal: 0.0,
                expectedTotal: 10.0,
                status: SalesReconciliationStatus::Ok,
                type: 'credit_note',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes/55555')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 55555, 'total' => 10.0]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->firstOrFail();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
});
