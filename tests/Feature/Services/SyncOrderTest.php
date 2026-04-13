<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
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
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['filter']['po_number'])))
        ->once()
        ->andReturn($invoiceNotFoundResponse);

    $productNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/products.json', Mockery::on(fn (array $args) => isset($args['filter']['product_code'])))
        ->once()
        ->andReturn($productNotFoundResponse);

    $productCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 67890]);
    $mockClient->shouldReceive('post')
        ->with('/api2/products.json', Mockery::any())
        ->once()
        ->andReturn($productCreateResponse);

    $clientNotFoundResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $mockClient->shouldReceive('get')
        ->with('/api2/clients.json', Mockery::on(fn (array $args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn($clientNotFoundResponse);

    $clientCreateResponse = mockHttpResponse(successful: true, status: 202, json: ['id' => 11111]);
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn($clientCreateResponse);

    $invoiceCreateResponse = mockHttpResponse(successful: true, status: 200, json: ['data' => ['id' => 12345]]);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) {
            expect($payload)->toHaveKey('Invoice');
            expect($payload['Invoice']['po_number'])->toBe('ebf8baa4-c847-41ad-8f04-198f2ee74dc0');
            expect($payload['Invoice']['client_id'])->toBe(11111);
            expect($payload['Invoice']['date'])->toBe('2019-11-28');
            expect($payload['Invoice']['discount_amount'])->toBe(5);
            expect($payload['Invoice']['notes'])->toBe('Some Kitchen Notes 73664');
            expect($payload)->toHaveKey('InvoiceItem');
            expect($payload['InvoiceItem'])->toHaveCount(1);
            expect($payload['InvoiceItem'][0])->toBe([
                'product_id' => 67890,
                'item' => 'Tuna Sandwich',
                'quantity' => 2,
                'unit_price' => 14,
                'discount' => 20,
                'discount_type' => 1,
            ]);

            return true;
        }))
        ->once()
        ->andReturn($invoiceCreateResponse);

    $paymentResponse = mockHttpResponse(successful: true, status: 200, json: []);
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices/12345/payments', [
            'payment_method' => 'Card',
            'amount' => 24.15,
            'date' => '2019-11-28 06:07:00',
        ])
        ->once()
        ->andReturn($paymentResponse);

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->order);

    expect(Invoice::where('foodics_id', $this->order['id'])->where('daftra_id', 12345)->exists())->toBeTrue();
    expect(Client::where('foodics_id', '8d831d65')->where('daftra_id', 11111)->exists())->toBeTrue();
    expect(Product::where('foodics_id', '8d90b8d1')->where('daftra_id', 67890)->exists())->toBeTrue();
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

        public function body(): string
        {
            return json_encode($this->json);
        }
    };
}
