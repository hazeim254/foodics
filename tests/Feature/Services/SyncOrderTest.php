<?php

use App\Models\Client;
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

it('syncs an order end-to-end with mocked Daftra API', function () {
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

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
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

    // Tax lookup - VAT tax (8d84bebc) not cached
    $taxNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['filter']['name'])))
        ->once()
        ->andReturn($taxNotFoundResponse);

    // Tax creation
    $taxCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxCreateResponse);

    $paymentGatewayListResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    $paymentGatewayCreateResponse = mockHttpResponse(successful: true, status: 201, json: ['id' => 424242]);
    $mockClient->shouldReceive('post')
        ->with('/v2/api/entity/site_payment_gateway', Mockery::any())
        ->once()
        ->andReturn($paymentGatewayCreateResponse);

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
            // Now expects 2 items: 1 product + 1 charge
            expect($payload['InvoiceItem'])->toHaveCount(2);
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
            // Second item is the Service Charge
            expect($payload['InvoiceItem'][1]['item'])->toBe('Service Charge');
            expect($payload['InvoiceItem'][1]['quantity'])->toBe(1);
            expect($payload['InvoiceItem'][1]['unit_price'])->toBe(8);
            expect($payload['InvoiceItem'][1]['tax1'])->toBe(54321);
            expect($payload['InvoiceItem'][1]['tax2'])->toBeNull();

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: []);
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
    expect(Client::where('foodics_id', '8d831d65')->where('daftra_id', 11111)->exists())->toBeTrue();
    expect(Product::where('foodics_id', '8d90b8d1')->where('daftra_id', 67890)->exists())->toBeTrue();
});

it('does not fetch product details from Foodics during sync', function () {
    $orderProduct = $this->order['products'][0];
    $this->order['products'] = [$orderProduct, $orderProduct];

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

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
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

    $paymentGatewayListResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentGatewayListResponse);

    $paymentGatewayCreateResponse = mockHttpResponse(successful: true, status: 201, json: ['id' => 424242]);
    $mockClient->shouldReceive('post')
        ->with('/v2/api/entity/site_payment_gateway', Mockery::any())
        ->once()
        ->andReturn($paymentGatewayCreateResponse);

    $invoiceCreateResponse = mockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'])->toHaveCount(3);

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: []);
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
        ->toThrow(RuntimeException::class, 'Order product line is missing a Foodics product id.');
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
