<?php

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use App\Exceptions\InvalidOrderLineException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\ProductService;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->order = json_decode(file_get_contents(base_path('json-stubs/foodics/get-order.json')), true)['order'];
});

it('syncs an order end-to-end with mocked Daftra API', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $productNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
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
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P001'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90b7dd'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P003'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '9ca37d4e-cbba-4d73-bdd9-ea4f2fb85d79'])
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->times(4)
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', ['filter' => ['client_number' => '8d831d65']])
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $taxNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->once()
        ->andReturn($taxNotFoundResponse);

    $taxCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxCreateResponse);

    $paymentGatewayListResponse = mockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card'],
        ],
    ]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    $invoiceCreateResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('Invoice');
            expect($payload['Invoice']['po_number'])->toBe('ebf8baa4-c847-41ad-8f04-198f2ee74dc0');
            expect($payload['Invoice']['client_id'])->toBe(11111);
            expect($payload['Invoice']['date'])->toBe('2019-11-28');
            expect($payload['Invoice']['discount_amount'])->toBe(5);
            expect($payload['Invoice']['notes'])->toBe('Some Kitchen Notes 73664');
            expect($payload)->toHaveKey('InvoiceItem');
            expect($payload['InvoiceItem'])->toHaveCount(5);
            expect($payload['InvoiceItem'][0])->toBe([
                'product_id' => 67890,
                'item' => 'Tuna Sandwich',
                'quantity' => 2,
                'unit_price' => 14,
                'discount' => 20,
                'discount_type' => 1,
                'tax1' => 54321,
                'tax2' => null,
            ]);
            expect($payload['InvoiceItem'][1]['item'])->toBe('Cheese Slice');
            expect($payload['InvoiceItem'][1]['product_id'])->toBe(67890);
            expect($payload['InvoiceItem'][1]['quantity'])->toBe(2);
            expect($payload['InvoiceItem'][1]['unit_price'])->toBe(3);
            expect($payload['InvoiceItem'][1]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][1]['tax2'])->toBeNull();
            expect($payload['InvoiceItem'][2]['item'])->toBe('Burger');
            expect($payload['InvoiceItem'][2]['quantity'])->toBe(2);
            expect($payload['InvoiceItem'][2]['unit_price'])->toBe(0);
            expect($payload['InvoiceItem'][2]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][3]['item'])->toBe('Salad');
            expect($payload['InvoiceItem'][3]['quantity'])->toBe(2);
            expect($payload['InvoiceItem'][3]['unit_price'])->toBe(0);
            expect($payload['InvoiceItem'][3]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][4]['item'])->toBe('Service Charge');
            expect($payload['InvoiceItem'][4]['quantity'])->toBe(1);
            expect($payload['InvoiceItem'][4]['unit_price'])->toBe(8);
            expect($payload['InvoiceItem'][4]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][4]['tax2'])->toBeNull();

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $listPaymentsEmptyResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn($listPaymentsEmptyResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 1]);
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
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => 11111]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    $invoice = Invoice::where('foodics_id', $this->order['id'])->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->daftra_id)->toBe(12345);
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
    expect(Client::where('foodics_id', '8d831d65')->where('daftra_id', 11111)->exists())->toBeTrue();
    expect(Product::where('foodics_id', '8d90b8d1')->where('daftra_id', 67890)->exists())->toBeTrue();
    expect($invoice->foodics_metadata)->toBe([
        'total_price' => 24.15,
    ]);
    expect($invoice->daftra_metadata)->toBe([
        'no' => 'INV-001',
        'client_id' => 11111,
    ]);
});

it('does not fetch product details from Foodics during sync', function () {
    $orderProduct = $this->order['products'][0];
    $this->order['products'] = [$orderProduct, $orderProduct];
    $this->order['combos'] = [];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $invoiceNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->once()
        ->andReturn($invoiceNotFoundResponse);

    $productNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
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

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->twice()
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::on(fn (array $args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $taxNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxNotFoundResponse);

    $taxCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxCreateResponse);

    $paymentGatewayListResponse = mockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card'],
        ],
    ]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    $invoiceCreateResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            // 2 products x (1 + 1 option) + 1 charge = 5
            expect($payload['InvoiceItem'])->toHaveCount(5);

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $listPaymentsEmptyResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn($listPaymentsEmptyResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 1]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('InvoicePayment');
            expect($payload['InvoicePayment']['invoice_id'])->toBe(12345);
            expect($payload['InvoicePayment']['payment_method'])->toBe('card');
            expect($payload['InvoicePayment'])->toHaveKeys(['amount', 'date']);

            return true;
        }))
        ->once()
        ->andReturn($paymentResponse);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => 11111]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);
});

it('throws when embedded product id is missing', function () {
    data_forget($this->order, 'products.0.product.id');
    unset($this->order['products'][0]['id']);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $syncOrder = $this->app->make(SyncOrder::class);

    expect(fn () => $syncOrder->getInvoiceItems($this->order['products']))
        ->toThrow(InvalidOrderLineException::class, 'Order product line is missing a Foodics product id.');
});

it('skips order already synced locally', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->order['id'],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    expect(Invoice::where('foodics_id', $this->order['id'])->count())->toBe(1);
});

it('stores foodics_metadata and daftra_metadata on invoice', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $productNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
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
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P001'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90b7dd'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P003'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '9ca37d4e-cbba-4d73-bdd9-ea4f2fb85d79'])
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->times(4)
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', ['filter' => ['client_number' => '8d831d65']])
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $taxNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->once()
        ->andReturn($taxNotFoundResponse);

    $taxCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxCreateResponse);

    $paymentGatewayListResponse = mockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card'],
        ],
    ]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    $invoiceCreateResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn($invoiceCreateResponse);

    $listPaymentsEmptyResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn($listPaymentsEmptyResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 1]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn($paymentResponse);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => 11111]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    $invoice = Invoice::where('foodics_id', $this->order['id'])->first();

    expect($invoice->foodics_metadata)->toBe([
        'total_price' => 24.15,
    ]);

    expect($invoice->daftra_metadata)->toBe([
        'no' => 'INV-001',
        'client_id' => 11111,
    ]);
});

it('emits each modifier option as its own invoice line', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(100);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $product = $this->order['products'][0];
    $product['options'] = [
        [
            'id' => 'opt-1',
            'quantity' => 1,
            'unit_price' => 2.5,
            'modifier_option' => ['id' => 'opt-1', 'name' => 'Extra Shot', 'sku' => 'OS1', 'price' => 2.5, 'cost' => null, 'is_active' => true],
            'taxes' => [],
        ],
        [
            'id' => 'opt-2',
            'quantity' => 2,
            'unit_price' => 1.0,
            'modifier_option' => ['id' => 'opt-2', 'name' => 'Cheese', 'sku' => 'OS2', 'price' => 1.0, 'cost' => null, 'is_active' => true],
            'taxes' => [],
        ],
    ];
    $products = [$product];

    $items = $syncOrder->getInvoiceItems($products);

    expect($items)->toHaveCount(3);
    expect($items[0]['item'])->toBe('Tuna Sandwich');
    expect($items[1]['item'])->toBe('Extra Shot');
    expect($items[2]['item'])->toBe('Cheese');
});

it('uses modifier_option.name as the option line item name', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(200);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $option = [
        'id' => 'opt-name',
        'quantity' => 1,
        'unit_price' => 5,
        'modifier_option' => ['id' => 'opt-name', 'name' => 'Avocado Spread', 'sku' => 'AVC', 'price' => 5, 'cost' => null, 'is_active' => true],
        'taxes' => [],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['item'])->toBe('Avocado Spread');
});

it('propagates option quantity, unit_price, and taxes to the invoice line', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(300);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, ['tax-abc' => 99]);

    $option = [
        'id' => 'opt-qty',
        'quantity' => 3,
        'unit_price' => 4.5,
        'modifier_option' => ['id' => 'opt-qty', 'name' => 'Syrup', 'sku' => 'SYR', 'price' => 4.5, 'cost' => null, 'is_active' => true],
        'taxes' => [['id' => 'tax-abc', 'name' => 'VAT', 'rate' => 5]],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['quantity'])->toBe(3);
    expect($item['unit_price'])->toBe(4.5);
    expect($item['tax1'])->toBe(99);
});

it('uses Daftra tax ids (not Foodics ids) for tax1 and tax2 on option lines', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(400);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, ['foodics-tax-1' => 99, 'foodics-tax-2' => 88]);

    $option = [
        'id' => 'opt-daftra',
        'quantity' => 1,
        'unit_price' => 10,
        'modifier_option' => ['id' => 'opt-daftra', 'name' => 'Test', 'sku' => 'T', 'price' => 10, 'cost' => null, 'is_active' => true],
        'taxes' => [
            ['id' => 'foodics-tax-1', 'name' => 'VAT', 'rate' => 5],
            ['id' => 'foodics-tax-2', 'name' => 'SVC', 'rate' => 10],
        ],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['tax1'])->toBe(99);
    expect($item['tax2'])->toBe(88);
    expect($item['tax1'])->toBeInt();
    expect($item['tax2'])->toBeInt();
});

it('caps option line taxes at two and warns when more are present', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(500);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $taxMapProp = $reflection->getProperty('taxMap');
    $taxMapProp->setAccessible(true);
    $taxMapProp->setValue($syncOrder, ['t1' => 11, 't2' => 22, 't3' => 33]);

    $orderIdProp = $reflection->getProperty('currentOrderId');
    $orderIdProp->setAccessible(true);
    $orderIdProp->setValue($syncOrder, 'order-abc-123');

    $option = [
        'id' => 'opt-cap',
        'quantity' => 1,
        'unit_price' => 10,
        'modifier_option' => ['id' => 'opt-cap', 'name' => 'Test', 'sku' => 'TC', 'price' => 10, 'cost' => null, 'is_active' => true],
        'taxes' => [
            ['id' => 't1', 'name' => 'Tax1', 'rate' => 5],
            ['id' => 't2', 'name' => 'Tax2', 'rate' => 10],
            ['id' => 't3', 'name' => 'Tax3', 'rate' => 15],
        ],
    ];

    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'more than 2 taxes')
            && $context['dropped_foodics_tax_ids'] === ['t3']
            && $context['order_id'] === 'order-abc-123';
    });

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['tax1'])->toBe(11);
    expect($item['tax2'])->toBe(22);
});

it('reports only resolved excess tax ids when dropping', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(500);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $taxMapProp = $reflection->getProperty('taxMap');
    $taxMapProp->setAccessible(true);
    // t2 is NOT in the map (unresolved); t1, t3, t4, t5 are resolved
    $taxMapProp->setValue($syncOrder, ['t1' => 11, 't3' => 33, 't4' => 44, 't5' => 55]);

    $orderIdProp = $reflection->getProperty('currentOrderId');
    $orderIdProp->setAccessible(true);
    $orderIdProp->setValue($syncOrder, 'order-resolved-excess');

    $option = [
        'id' => 'opt-resolved',
        'quantity' => 1,
        'unit_price' => 10,
        'modifier_option' => ['id' => 'opt-resolved', 'name' => 'Test', 'sku' => 'TR', 'price' => 10, 'cost' => null, 'is_active' => true],
        'taxes' => [
            ['id' => 't1', 'name' => 'Tax1', 'rate' => 5],
            ['id' => 't2', 'name' => 'Tax2', 'rate' => 10],
            ['id' => 't3', 'name' => 'Tax3', 'rate' => 15],
            ['id' => 't4', 'name' => 'Tax4', 'rate' => 20],
            ['id' => 't5', 'name' => 'Tax5', 'rate' => 25],
        ],
    ];

    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        // After filtering out t2 (unresolved), resolved order is: t1, t3, t4, t5
        // Positions >= 2 in the post-filter list: t4, t5
        return str_contains($message, 'more than 2 taxes')
            && $context['dropped_foodics_tax_ids'] === ['t4', 't5']
            && $context['order_id'] === 'order-resolved-excess';
    });

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    // tax1 and tax2 should be the first two resolved Daftra ids: 11 and 33
    expect($item['tax1'])->toBe(11);
    expect($item['tax2'])->toBe(33);
});

it('skips unresolved Foodics tax ids on option lines', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(600);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, ['t1' => 11, 't3' => 33]);

    $option = [
        'id' => 'opt-skip',
        'quantity' => 1,
        'unit_price' => 10,
        'modifier_option' => ['id' => 'opt-skip', 'name' => 'Test', 'sku' => 'TS', 'price' => 10, 'cost' => null, 'is_active' => true],
        'taxes' => [
            ['id' => 't1', 'name' => 'Tax1', 'rate' => 5],
            ['id' => 't2-unresolved', 'name' => 'Tax2', 'rate' => 10],
            ['id' => 't3', 'name' => 'Tax3', 'rate' => 15],
        ],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['tax1'])->toBe(11);
    expect($item['tax2'])->toBe(33);
});

it('falls back to tax_exclusive_discount_amount when option discount_amount is missing', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(700);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $option = [
        'id' => 'opt-disc',
        'quantity' => 1,
        'unit_price' => 10,
        'tax_exclusive_discount_amount' => 1.5,
        'modifier_option' => ['id' => 'opt-disc', 'name' => 'Test', 'sku' => 'TD', 'price' => 10, 'cost' => null, 'is_active' => true],
        'taxes' => [],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['discount'])->toBe(1.5);
});

it('emits zero-price options as invoice lines', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(800);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $option = [
        'id' => 'opt-free',
        'quantity' => 1,
        'unit_price' => 0,
        'modifier_option' => ['id' => 'opt-free', 'name' => 'Free Topping', 'sku' => 'FT', 'price' => 0, 'cost' => null, 'is_active' => true],
        'taxes' => [],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item)->not->toBeNull();
    expect($item['unit_price'])->toBe(0);
});

it('falls back to a generic name when modifier_option sub-object is missing', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(900);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $option = [
        'id' => 'opt-nomod',
        'quantity' => 1,
        'unit_price' => 5,
        'taxes' => [],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);
    $item = $method->invoke($syncOrder, $option);

    expect($item['item'])->toBe('Modifier Option');

    $mockProductService->shouldHaveReceived('getProductByFoodicsData')
        ->with(Mockery::on(fn (array $data) => $data['id'] === 'opt-nomod' && $data['sku'] === 'opt-nomod'));
});

it('throws InvalidOrderLineException when an option has no resolvable id', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $mockProductService = Mockery::mock(ProductService::class);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $option = [
        'quantity' => 1,
        'unit_price' => 5,
        'taxes' => [],
    ];

    $method = $reflection->getMethod('buildOptionInvoiceItem');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($syncOrder, $option))
        ->toThrow(InvalidOrderLineException::class, 'Order option line is missing a Foodics id.');
});

it('throws InvalidOrderLineException when a product line has no resolvable id', function () {
    data_forget($this->order, 'products.0.product.id');
    unset($this->order['products'][0]['id']);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $syncOrder = $this->app->make(SyncOrder::class);

    expect(fn () => $syncOrder->getInvoiceItems($this->order['products']))
        ->toThrow(InvalidOrderLineException::class, 'Order product line is missing a Foodics product id.');
});

it('routes status-4 orders to the invoice path and status-5 orders to the credit-note path', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::on(fn (array $args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 11111]));

    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 54321]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => 11111]],
        ]));

    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $order4 = $this->order;
    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order4);

    $invoiceRow = Invoice::where('foodics_id', $order4['id'])->first();
    expect($invoiceRow)->not->toBeNull();
    expect($invoiceRow->type)->toBe(InvoiceType::Invoice);
    expect($invoiceRow->daftra_id)->toBe(12345);

    $returnOrder = [
        'id' => 'return-uuid-001',
        'reference' => '00300',
        'status' => 5,
        'business_date' => '2026-04-15',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 10,
        'original_order' => ['id' => $order4['id'], 'reference' => $order4['reference']],
        'customer' => null,
        'products' => [],
        'charges' => [],
        'payments' => [],
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($returnOrder);

    $creditNote = Invoice::where('foodics_id', 'return-uuid-001')->first();
    expect($creditNote)->not->toBeNull();
    expect($creditNote->type)->toBe(InvoiceType::CreditNote);
    expect($creditNote->original_invoice_id)->toBe($invoiceRow->id);
    expect($creditNote->daftra_id)->toBe(55555);
});

it('emits combo products as normal invoice items', function () {
    $comboOrder = json_decode(file_get_contents(base_path('json-stubs/foodics/combo-order.json')), true)['data'][0];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Cash', 'payment_gateway' => 'cash']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'])->toHaveCount(2);
            expect($payload['InvoiceItem'][0]['item'])->toBe('Medium Burger');
            expect($payload['InvoiceItem'][1]['item'])->toBe('Fries');

            return true;
        }))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($comboOrder);
});

it('does not emit a combo wrapper invoice item', function () {
    $comboOrder = json_decode(file_get_contents(base_path('json-stubs/foodics/combo-order.json')), true)['data'][0];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Cash', 'payment_gateway' => 'cash']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            $names = collect($payload['InvoiceItem'])->pluck('item')->all();
            expect($names)->not->toContain('Combo');

            return true;
        }))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($comboOrder);
});

it('processes normal products before combo products', function () {
    $order = [
        'id' => 'order-mixed',
        'reference' => '00100',
        'status' => 4,
        'business_date' => '2026-04-28',
        'discount_amount' => 0,
        'kitchen_notes' => null,
        'total_price' => 100,
        'customer' => null,
        'products' => [
            [
                'id' => 'normal-1',
                'quantity' => 1,
                'unit_price' => 50,
                'discount_amount' => 0,
                'discount_type' => 2,
                'product' => ['id' => 'normal-1', 'name' => 'Normal Product', 'sku' => 'NP1', 'price' => 50, 'cost' => null, 'is_active' => true, 'description' => '', 'barcode' => null],
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [
            [
                'id' => 'combo-1',
                'products' => [
                    [
                        'id' => 'combo-p1',
                        'quantity' => 1,
                        'unit_price' => 30,
                        'discount_amount' => 0,
                        'product' => ['id' => 'combo-p1', 'name' => 'Combo Product A', 'sku' => 'CP1', 'price' => 30, 'cost' => null, 'is_active' => true],
                        'taxes' => [],
                    ],
                    [
                        'id' => 'combo-p2',
                        'quantity' => 1,
                        'unit_price' => 20,
                        'discount_amount' => 0,
                        'product' => ['id' => 'combo-p2', 'name' => 'Combo Product B', 'sku' => 'CP2', 'price' => 20, 'cost' => null, 'is_active' => true],
                        'taxes' => [],
                    ],
                ],
                'discount_type' => null,
                'discount_amount' => 0,
                'quantity' => 1,
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 1, 'label' => 'Cash', 'payment_gateway' => 'cash']],
        ]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'])->toHaveCount(3);
            expect($payload['InvoiceItem'][0]['item'])->toBe('Normal Product');
            expect($payload['InvoiceItem'][1]['item'])->toBe('Combo Product A');
            expect($payload['InvoiceItem'][2]['item'])->toBe('Combo Product B');

            return true;
        }))
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', ['filter[invoice_id]' => 12345])
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices/12345')
        ->once()
        ->andReturn(mockHttpResponse(successful: true, status: 200, json: [
            'data' => ['Invoice' => ['id' => 12345, 'no' => 'INV-001', 'client_id' => null]],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($order);
});

it('uses embedded combo product metadata for Daftra product resolution', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')
        ->with(Mockery::on(fn (array $data) => $data['id'] === 'a04648bb-911f-4f58-a058-aa5fc68d9015' && $data['name'] === 'Medium Burger' && $data['sku'] === 'sk-0001'))
        ->once()
        ->andReturn(100);
    $mockProductService->shouldReceive('getProductByFoodicsData')
        ->with(Mockery::on(fn (array $data) => $data['id'] === 'a1a34a7a-eecb-4647-ae5a-56b8f973b422' && $data['name'] === 'Fries' && $data['sku'] === 'sk-0003'))
        ->once()
        ->andReturn(200);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $comboOrder = json_decode(file_get_contents(base_path('json-stubs/foodics/combo-order.json')), true)['data'][0];
    $method = $reflection->getMethod('getOrderProductLines');
    $method->setAccessible(true);
    $productLines = $method->invoke($syncOrder, $comboOrder);

    $syncOrder->getInvoiceItems($productLines);
});

it('throws InvalidOrderLineException when a combo product has no resolvable Foodics id', function () {
    $comboOrder = json_decode(file_get_contents(base_path('json-stubs/foodics/combo-order.json')), true)['data'][0];

    data_forget($comboOrder, 'combos.0.products.0.product.id');
    unset($comboOrder['combos'][0]['products'][0]['id']);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $method = $reflection->getMethod('getOrderProductLines');
    $method->setAccessible(true);
    $productLines = $method->invoke($syncOrder, $comboOrder);

    expect(fn () => $syncOrder->getInvoiceItems($productLines))
        ->toThrow(InvalidOrderLineException::class, 'Order product line is missing a Foodics product id.');
});

it('ignores modifier options on combo products', function () {
    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(100);
    $this->app->instance(ProductService::class, $mockProductService);

    $syncOrder = $this->app->make(SyncOrder::class);
    $reflection = new ReflectionClass($syncOrder);
    $prop = $reflection->getProperty('taxMap');
    $prop->setAccessible(true);
    $prop->setValue($syncOrder, []);

    $order = [
        'products' => [],
        'combos' => [
            [
                'products' => [
                    [
                        'id' => 'cp-1',
                        'quantity' => 1,
                        'unit_price' => 10,
                        'discount_amount' => 0,
                        'product' => ['id' => 'cp-1', 'name' => 'Combo Item', 'sku' => 'CI1', 'price' => 10, 'cost' => null, 'is_active' => true],
                        'taxes' => [],
                        'options' => [
                            [
                                'id' => 'opt-1',
                                'quantity' => 1,
                                'unit_price' => 2,
                                'modifier_option' => ['id' => 'opt-1', 'name' => 'Extra', 'sku' => 'E1', 'price' => 2, 'cost' => null, 'is_active' => true],
                                'taxes' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $method = $reflection->getMethod('getOrderProductLines');
    $method->setAccessible(true);
    $productLines = $method->invoke($syncOrder, $order);

    $items = $syncOrder->getInvoiceItems($productLines);

    expect($items)->toHaveCount(1);
    expect($items[0]['item'])->toBe('Combo Item');
});

function mockHttpResponse(bool $successful, int $status, array $json): object
{
    return new class($successful, $status, $json)
    {
        public function __construct(
            private bool $successful,
            private int $status,
            private array $json,
        ) {}

        public function successful(): bool
        {
            return $this->successful;
        }

        public function failed(): bool
        {
            return ! $this->successful;
        }

        public function status(): int
        {
            return $this->status;
        }

        public function json($key = null, $default = null): mixed
        {
            if ($key === null) {
                return $this->json;
            }

            return data_get($this->json, $key, $default);
        }

        public function throw(): static
        {
            return $this;
        }

        public function body(): string
        {
            return json_encode($this->json);
        }
    };
}
