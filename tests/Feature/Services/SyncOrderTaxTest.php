<?php

use App\Models\Client;
use App\Models\EntityMapping;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->order = json_decode(file_get_contents(base_path('json-stubs/foodics/get-order.json')), true)['order'];
});

it('syncs an order with taxes end-to-end', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);

    $invoiceNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->once()
        ->andReturn($invoiceNotFoundResponse);

    // Product lookup
    $productNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P002'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90b8d1'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'M002'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90d06e'])
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->twice()
        ->andReturn($productCreateResponse);

    // Client lookup
    $clientNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::on(fn (array $args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    // Tax lookup - VAT tax (8d84bebc) not cached
    $taxNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->once()
        ->andReturn($taxNotFoundResponse);

    // Tax creation
    $taxCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::on(function (array $payload) {
            return $payload['Tax']['name'] === 'VAT' && $payload['Tax']['value'] === 5.0;
        }))
        ->once()
        ->andReturn($taxCreateResponse);

    $paymentGatewayListResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card'],
        ],
    ]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    // Invoice creation with tax data
    $invoiceCreateResponse = createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('Invoice');
            expect($payload)->toHaveKey('InvoiceItem');

            // 1 product + 1 option + 1 charge = 3
            expect($payload['InvoiceItem'])->toHaveCount(3);

            // First item: product with tax
            expect($payload['InvoiceItem'][0]['item'])->toBe('Tuna Sandwich');
            expect($payload['InvoiceItem'][0]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][0]['tax2'])->toBeNull();

            // Second item: option (Cheese Slice) with same tax
            expect($payload['InvoiceItem'][1]['item'])->toBe('Cheese Slice');
            expect($payload['InvoiceItem'][1]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][1]['tax2'])->toBeNull();

            // Third item: Service Charge with tax
            expect($payload['InvoiceItem'][2]['item'])->toBe('Service Charge');
            expect($payload['InvoiceItem'][2]['unit_price'])->toBe(8);
            expect($payload['InvoiceItem'][2]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][2]['tax2'])->toBeNull();

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $listPaymentsEmptyResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn($listPaymentsEmptyResponse);

    $paymentResponse = createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('InvoicePayment');
            expect($payload['InvoicePayment']['invoice_id'])->toBe(12345);
            expect($payload['InvoicePayment']['payment_method'])->toBe('card');
            expect($payload['InvoicePayment']['amount'])->toBe(24.15);
            expect($payload['InvoicePayment']['date'])->toBe('2019-11-28 06:07:00');

            return true;
        }))
        ->once()
        ->andReturn($paymentResponse);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001']],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    expect(Invoice::where('foodics_id', $this->order['id'])->where('daftra_id', 12345)->exists())->toBeTrue();
    expect(EntityMapping::where('foodics_id', '8d84bebc')->where('daftra_id', 54321)->exists())->toBeTrue();
});

it('uses cached tax mapping when available', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'tax',
        'foodics_id' => '8d84bebc',
        'daftra_id' => 99999,
        'metadata' => ['name' => 'VAT', 'rate' => 5],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $invoiceNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn($invoiceNotFoundResponse);

    $productNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P002'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90b8d1'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'M002'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90d06e'])
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->twice()
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $paymentGatewayListResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card'],
        ],
    ]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    // Tax API should NOT be called when cached
    $mockClient->shouldNotReceive('get')
        ->with('/api2/taxes.json', Mockery::any());

    $invoiceCreateResponse = createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'][0]['tax1'])->toBe(99999);
            expect($payload['InvoiceItem'][1]['tax1'])->toBe(99999);

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $listPaymentsEmptyResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn($listPaymentsEmptyResponse);

    $paymentResponse = createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('InvoicePayment');
            expect($payload['InvoicePayment']['invoice_id'])->toBe(12345);
            expect($payload['InvoicePayment']['payment_method'])->toBe('card');
            expect($payload['InvoicePayment']['amount'])->toBe(24.15);
            expect($payload['InvoicePayment']['date'])->toBe('2019-11-28 06:07:00');

            return true;
        }))
        ->once()
        ->andReturn($paymentResponse);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001']],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    expect(EntityMapping::where('foodics_id', '8d84bebc')->where('daftra_id', 99999)->exists())->toBeTrue();
});

it('resolves option taxes into taxMap before getInvoiceItems runs', function () {
    $mockDaftraClient = Mockery::mock(DaftraApiClient::class);

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(100);
    $this->app->instance(ProductService::class, $mockProductService);

    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturnUsing(fn (array $tax) => match ($tax['id']) {
        'opt-tax-1' => 777,
        default => 888,
    });
    $this->app->instance(TaxService::class, $mockTaxService);

    $order = [
        'id' => 'test-order',
        'reference' => '00100',
        'business_date' => '2026-01-01',
        'products' => [
            [
                'id' => 'prod-1',
                'product' => ['id' => 'prod-1', 'name' => 'Burger', 'sku' => 'B1', 'price' => 10],
                'quantity' => 1,
                'unit_price' => 10,
                'taxes' => [],
                'options' => [
                    [
                        'id' => 'opt-1',
                        'quantity' => 1,
                        'unit_price' => 2,
                        'modifier_option' => ['id' => 'opt-1', 'name' => 'Extra Sauce', 'sku' => 'ES', 'price' => 2, 'cost' => null, 'is_active' => true],
                        'taxes' => [['id' => 'opt-tax-1', 'name' => 'OptionTax', 'rate' => 10]],
                    ],
                ],
            ],
        ],
        'charges' => [],
    ];

    $invoice = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $order['id'],
        'foodics_reference' => $order['reference'],
        'daftra_id' => null,
    ]);

    $mockDaftraClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));
    $mockDaftraClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            $optionLine = collect($payload['InvoiceItem'])->first(fn ($i) => $i['item'] === 'Extra Sauce');
            expect($optionLine)->not->toBeNull();
            expect($optionLine['tax1'])->toBe(777);
            expect($optionLine['tax1'])->toBeInt();

            return true;
        }))
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 999]));
    $mockDaftraClient->shouldReceive('get')
        ->with('/api2/invoices/999')
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 999, 'no' => 'INV-002']],
        ]));

    $mockDaftraClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 999])
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $clientNotFoundResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockDaftraClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn($clientNotFoundResponse);

    $paymentGatewayResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [['id' => 1, 'label' => 'Cash', 'payment_gateway' => 'cash']],
    ]);
    $mockDaftraClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->andReturn($paymentGatewayResponse);

    $this->app->instance(DaftraApiClient::class, $mockDaftraClient);

    $syncOrder = $this->app->make(SyncOrder::class);

    $reflection = new ReflectionClass($syncOrder);
    $runSync = $reflection->getMethod('runSync');
    $runSync->setAccessible(true);

    $runSync->invoke($syncOrder, $order, $invoice);
});
