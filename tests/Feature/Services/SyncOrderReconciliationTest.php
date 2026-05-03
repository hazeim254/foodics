<?php

use App\Dtos\Reconciliation\SalesReconciliationResult;
use App\Dtos\Reconciliation\SalesReconciliationSummary;
use App\Enums\InvoiceSyncStatus;
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
});

it('stores reconciliation metadata on the local invoice row after completed order sync', function () {
    $order = json_decode(file_get_contents(base_path('json-stubs/foodics/get-order.json')), true)['order'];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $productNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn($productNotFoundResponse);

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::on(fn (array $args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]));

    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 54321]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => 11111]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', $order['id'])->first();

    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
    expect($invoice->foodics_metadata)->toHaveKey('sales_reconciliation');

    $recon = $invoice->foodics_metadata['sales_reconciliation'];
    expect($recon)->toHaveKey('status');
    expect($recon)->toHaveKey('summary');
    expect($recon)->toHaveKey('differences');
    expect($recon)->toHaveKey('tolerance');
    expect($recon)->toHaveKey('checked_at');
    expect($recon['tolerance'])->toBe(0.01);
});

it('persisted reconciliation uses ok status when amounts match', function () {
    $order = [
        'id' => 'order-recon-ok',
        'reference' => '00100',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 50.0,
        'subtotal_price' => 50.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 50.0,
                productTotal: 0.0,
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
                expectedTotal: 50.0,
                status: SalesReconciliationStatus::Ok,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-recon-ok')->firstOrFail();
    expect($invoice->foodics_metadata['sales_reconciliation']['status'])->toBe('ok');
});

it('logs warning for mismatch reconciliation', function () {
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'Sales reconciliation mismatch')
            && isset($context['order_id'])
            && isset($context['invoice_id'])
            && $context['invoice_type'] === 'invoice';
    });

    $order = [
        'id' => 'order-mismatch-1',
        'reference' => '00400',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 999.0,
        'subtotal_price' => 999.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Mismatch,
            summary: new SalesReconciliationSummary(
                subtotal: 999.0,
                productTotal: 0.0,
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
                expectedTotal: 999.0,
                status: SalesReconciliationStatus::Mismatch,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-mismatch-1')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
    expect($invoice->foodics_metadata['sales_reconciliation']['status'])->toBe('mismatch');
});

it('does not prevent invoice sync when reconciliation fails', function () {
    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andThrow(new RuntimeException('Reconciliation exploded'));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'Reconciliation failed after sync')
            && isset($context['order_id']);
    });

    $order = [
        'id' => 'order-recon-err',
        'reference' => '00500',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 50.0,
        'subtotal_price' => 50.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-recon-err')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
});

it('does not trigger extra product or client creation during reconciliation persistence', function () {
    $order = [
        'id' => 'order-no-rebuild',
        'reference' => '00600',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 50.0,
        'subtotal_price' => 50.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 50.0,
                productTotal: 0.0,
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
                expectedTotal: 50.0,
                status: SalesReconciliationStatus::Ok,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $productCreationCalls = 0;
    $clientCreationCalls = 0;

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturnUsing(function () use (&$productCreationCalls) {
            $productCreationCalls++;

            return createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
        });

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-no-rebuild')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
    expect($invoice->foodics_metadata)->toHaveKey('sales_reconciliation');

    $mockClient->shouldNotHaveReceived('post', ['/api2/clients.json']);
});

it('does not rebuild payload when reusing an existing Daftra invoice id', function () {
    $existingDaftraId = 88888;

    $invoice = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => 'order-reuse-existing',
        'foodics_reference' => '00700',
        'daftra_id' => $existingDaftraId,
        'status' => InvoiceSyncStatus::Failed,
        'total_price' => 50.0,
    ]);

    $order = [
        'id' => 'order-reuse-existing',
        'reference' => '00700',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 50.0,
        'subtotal_price' => 50.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $order, array $payload, ?array $document) use ($existingDaftraId) {
            expect($payload)->toHaveKey('Invoice')
                ->and($payload['Invoice'])->toHaveKey('discount_amount')
                ->and($payload['InvoiceItem'])->toBeEmpty()
                ->and($document)->not->toBeNull()
                ->and($document['id'])->toBe($existingDaftraId);

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 50.0,
                productTotal: 0.0,
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
                expectedTotal: 50.0,
                status: SalesReconciliationStatus::Ok,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => $existingDaftraId])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with("/api2/invoices/{$existingDaftraId}")
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => $existingDaftraId, 'no' => 'INV-REUSE', 'client_id' => 42]],
        ]));

    $mockClient->shouldNotReceive('post')->with('/api2/products', Mockery::any());
    $mockClient->shouldNotReceive('post')->with('/api2/clients.json', Mockery::any());
    $mockClient->shouldNotReceive('post')->with('/api2/invoices', Mockery::any());

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-reuse-existing')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
    expect($invoice->foodics_metadata)->toHaveKey('sales_reconciliation');
});

it('passes existing Daftra payments into reconciliation so mismatches are detected', function () {
    $order = [
        'id' => 'order-existing-payments-recon',
        'reference' => '00800',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 100.0,
        'subtotal_price' => 100.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.0, 'tips' => 0, 'payment_method' => ['id' => 'pm1', 'name' => 'Cash', 'is_active' => true, 'reference' => null], 'added_at' => '2026-04-28'],
        ],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $orderArg, array $payload, ?array $document) {
            $paymentData = $payload['InvoicePayment'] ?? [];
            expect($paymentData)->not->toBeEmpty()
                ->and(count($paymentData))->toBe(1)
                ->and($paymentData[0]['InvoicePayment']['amount'])->toBe(75.0);

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Mismatch,
            summary: new SalesReconciliationSummary(
                subtotal: 100.0,
                productTotal: 0.0,
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
                paymentTotal: 100.0,
                expectedTotal: 100.0,
                status: SalesReconciliationStatus::Mismatch,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Cash', 'payment_gateway' => 'cash']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['InvoicePayment' => ['id' => 1, 'amount' => 75.0, 'payment_method' => 42]],
            ],
        ]));

    $mockClient->shouldNotReceive('post')->with('/api2/invoice_payments', Mockery::any());

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-existing-payments-recon')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
});

it('passes created payment payloads into reconciliation', function () {
    $order = [
        'id' => 'order-created-payments-recon',
        'reference' => '00900',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 100.0,
        'subtotal_price' => 100.0,
        'customer' => null,
        'products' => [],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.0, 'tips' => 0, 'payment_method' => ['id' => 'pm1', 'name' => 'Cash', 'is_active' => true, 'reference' => null], 'added_at' => '2026-04-28'],
        ],
    ];

    $mockReconService = Mockery::mock(SalesReconciliationService::class);
    $mockReconService->shouldReceive('compare')
        ->once()
        ->withArgs(function (array $orderArg, array $payload, ?array $document) {
            $paymentData = $payload['InvoicePayment'] ?? [];
            expect($paymentData)->not->toBeEmpty()
                ->and(count($paymentData))->toBe(1)
                ->and($paymentData[0]['InvoicePayment']['amount'])->toBe(100.0);

            return true;
        })
        ->andReturn(new SalesReconciliationResult(
            status: SalesReconciliationStatus::Ok,
            summary: new SalesReconciliationSummary(
                subtotal: 100.0,
                productTotal: 0.0,
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
                paymentTotal: 100.0,
                expectedTotal: 100.0,
                status: SalesReconciliationStatus::Ok,
                type: 'invoice',
                differences: [],
            ),
            differences: [],
            tolerance: 0.01,
            checkedAt: CarbonImmutable::now(),
        ));

    $this->app->instance(SalesReconciliationService::class, $mockReconService);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Cash', 'payment_gateway' => 'cash']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);

    $invoice = Invoice::where('foodics_id', 'order-created-payments-recon')->firstOrFail();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
});
