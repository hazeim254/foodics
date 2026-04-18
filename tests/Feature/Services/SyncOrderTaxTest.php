<?php

use App\Models\Client;
use App\Models\EntityMapping;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
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

    $productCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
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

            // Should have 2 invoice items: 1 product + 1 charge (Service Charge)
            expect($payload['InvoiceItem'])->toHaveCount(2);

            // First item: canonical product name with tax
            expect($payload['InvoiceItem'][0]['item'])->toBe('Tuna Sandwich');
            expect($payload['InvoiceItem'][0]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][0]['tax2'])->toBeNull();

            // Second item: Service Charge with tax
            expect($payload['InvoiceItem'][1]['item'])->toBe('Service Charge');
            expect($payload['InvoiceItem'][1]['unit_price'])->toBe(8);
            expect($payload['InvoiceItem'][1]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][1]['tax2'])->toBeNull();

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

    $productCreateResponse = createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
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

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldNotReceive('get');
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    expect(EntityMapping::where('foodics_id', '8d84bebc')->where('daftra_id', 99999)->exists())->toBeTrue();
});
