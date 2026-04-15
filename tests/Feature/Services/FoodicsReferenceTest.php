<?php

use App\Models\Invoice;
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
});

it('stores foodics_reference when syncing an order', function () {
    $order = json_decode(file_get_contents(base_path('json-stubs/foodics/get-order.json')), true)['order'];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $invoiceNotFoundResponse = mockResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn ($a) => isset($a['custom_field']) && isset($a['custom_field_label'])))
        ->once()
        ->andReturn($invoiceNotFoundResponse);

    $productNotFoundResponse = mockResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => 'P002-CANONICAL'])
        ->once()
        ->andReturn($productNotFoundResponse);
    $mockClient->shouldReceive('get')
        ->with('/api2/products', ['product_code' => '8d90b8d1'])
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = mockResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->once()
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = mockResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/clients.json', Mockery::on(fn ($a) => isset($a['filter']['client_number'])))
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = mockResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $taxNotFoundResponse = mockResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn ($a) => isset($a['filter']['name'])))
        ->once()
        ->andReturn($taxNotFoundResponse);

    $taxCreateResponse = mockResponse(successful: true, status: 202, json: ['id' => 54321]);
    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn($taxCreateResponse);

    $invoiceCreateResponse = mockResponse(successful: true, status: 200, json: ['id' => 12345]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn($invoiceCreateResponse);

    $paymentMethodNotFoundResponse = mockResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/site_payment_gateway/list/1.json')
        ->once()
        ->andReturn($paymentMethodNotFoundResponse);

    $paymentMethodCreateResponse = mockResponse(successful: true, status: 201, json: ['id' => 99999]);
    $mockClient->shouldReceive('post')
        ->with('/api2/site_payment_gateway.json', Mockery::any())
        ->once()
        ->andReturn($paymentMethodCreateResponse);

    $paymentResponse = mockResponse(successful: true, status: 200, json: []);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices/12345/payments', Mockery::any())
        ->once()
        ->andReturn($paymentResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $foodicsClient = Mockery::mock(FoodicsApiClient::class);
    $foodicsClient->shouldReceive('get')
        ->with('/products/8d90b8d1')
        ->once()
        ->andReturn(mockResponse(successful: true, status: 200, json: [
            'data' => [
                'id' => '8d90b8d1',
                'name' => 'Canonical Tuna Sandwich',
                'sku' => 'P002-CANONICAL',
                'description' => 'Canonical Product Description',
            ],
        ]));
    $this->app->instance(FoodicsApiClient::class, $foodicsClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('foodics_id', $order['id'])->where('daftra_id', 12345)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->foodics_reference)->toBe($order['reference']);
});

function mockResponse(bool $successful, int $status, array $json): object
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
