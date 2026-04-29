<?php

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use App\Exceptions\InvalidOrderLineException;
use App\Exceptions\OriginalInvoiceNotSyncedException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->originalFoodicsId = 'original-order-uuid-001';
    $this->returnFoodicsId = 'return-order-uuid-001';
    $this->originalDaftraId = 12345;

    $this->original = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->originalFoodicsId,
        'foodics_reference' => '00200',
        'daftra_id' => $this->originalDaftraId,
        'status' => InvoiceSyncStatus::Synced,
        'daftra_metadata' => ['no' => 'INV-001', 'client_id' => 42],
    ]);

    $this->returnOrder = makeReturnOrder();
});

function makeReturnOrder(): array
{
    return [
        'id' => 'return-order-uuid-001',
        'reference' => '00300',
        'status' => 5,
        'business_date' => '2026-04-15',
        'discount_amount' => 0,
        'kitchen_notes' => 'Return note',
        'total_price' => 10.0,
        'original_order' => ['id' => 'original-order-uuid-001', 'reference' => '00200'],
        'customer' => null,
        'products' => [
            [
                'id' => 'p1',
                'quantity' => 1,
                'unit_price' => 10,
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

function setupDaftraMockForCreditNote(): MockInterface
{
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn(mockReturnHttpResponse(successful: true, status: 202, json: ['id' => 999]));

    return $mockClient;
}

it('creates a credit note row linked to the original invoice', function () {
    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote)->not->toBeNull();
    expect($creditNote->type)->toBe(InvoiceType::CreditNote);
    expect($creditNote->original_invoice_id)->toBe($this->original->id);
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->daftra_id)->toBe(55555);
});

it('posts the credit note to Daftra with subscription_id equal to the original daftra_id', function () {
    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['Invoice']['subscription_id'])->toBe($this->originalDaftraId);

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('throws OriginalInvoiceNotSyncedException when the original is still pending', function () {
    $this->original->update(['status' => InvoiceSyncStatus::Pending]);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
})->throws(OriginalInvoiceNotSyncedException::class);

it('throws OriginalInvoiceNotSyncedException when the original row is absent', function () {
    $this->original->delete();

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
})->throws(OriginalInvoiceNotSyncedException::class);

it('throws OriginalInvoiceNotSyncedException when the original has no daftra_id', function () {
    $this->original->update(['daftra_id' => null]);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
})->throws(OriginalInvoiceNotSyncedException::class);

it('throws InvalidOrderLineException when original_order is missing from the return payload', function () {
    unset($this->returnOrder['original_order']);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
})->throws(InvalidOrderLineException::class, 'Return order is missing original_order reference.');

it('reuses existing Daftra credit note id on retry', function () {
    Invoice::factory()->creditNote($this->original)->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->returnFoodicsId,
        'foodics_reference' => '00300',
        'daftra_id' => 777,
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('post');

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->daftra_id)->toBe(777);
});

it('finds an existing Daftra credit note by Foodics custom field before creating', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/credit_notes', Mockery::on(fn ($args) => isset($args['custom_field'])))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: [
            'data' => [['Invoice' => ['id' => 888]]],
        ]));

    $mockClient->shouldNotReceive('post');

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote->daftra_id)->toBe(888);
});

it('emits all product, option, and charge lines on the credit note', function () {
    $this->returnOrder['products'] = [
        [
            'id' => 'p1',
            'quantity' => 2,
            'unit_price' => 10,
            'discount_amount' => 0,
            'discount_type' => 2,
            'product' => ['id' => 'p1', 'name' => 'Burger', 'sku' => '', 'price' => 10, 'cost' => null, 'is_active' => true, 'description' => '', 'barcode' => null],
            'taxes' => [],
            'options' => [
                [
                    'id' => 'opt-1',
                    'quantity' => 1,
                    'unit_price' => 2,
                    'modifier_option' => ['id' => 'opt-1', 'name' => 'Cheese', 'sku' => '', 'price' => 2, 'cost' => null, 'is_active' => true],
                    'taxes' => [],
                ],
            ],
        ],
    ];
    $this->returnOrder['charges'] = [
        [
            'amount' => 5,
            'charge' => ['name' => 'Service Charge'],
            'taxes' => [],
        ],
    ];

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'])->toHaveCount(3);
            expect($payload['InvoiceItem'][0]['item'])->toBe('Burger');
            expect($payload['InvoiceItem'][1]['item'])->toBe('Cheese');
            expect($payload['InvoiceItem'][2]['item'])->toBe('Service Charge');

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('uses the returns customer for client_id when present', function () {
    $this->returnOrder['customer'] = [
        'id' => 'cust-1',
        'name' => 'Test Customer',
        'phone' => '12345678',
        'email' => 'test@example.com',
        'client_number' => 'CUST-001',
    ];

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::on(fn ($args) => isset($args['filter']['client_number'])))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['data' => [['id' => 777]]]));

    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['Invoice']['client_id'])->toBe(777);

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('falls back to the original invoices daftra client_id when the return has no customer', function () {
    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['Invoice']['client_id'])->toBe(42);

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('falls back to the default client when neither the return nor the original has a client', function () {
    $this->original->update(['daftra_metadata' => ['no' => 'INV-001']]);

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['Invoice']['client_id'])->toBeNull();

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('sends discount_amount and notes from the return payload', function () {
    $this->returnOrder['discount_amount'] = 5;
    $this->returnOrder['kitchen_notes'] = 'Returned items';

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['Invoice']['discount_amount'])->toBe(5);
            expect($payload['Invoice']['notes'])->toBe('Returned items');

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('does not sync payments on returns and logs a warning when payments are present', function () {
    $this->returnOrder['payments'] = [
        ['amount' => 10, 'payment_method' => ['id' => 'pm-1'], 'added_at' => '2026-04-15'],
    ];

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $mockClient->shouldNotReceive('post')
        ->with('/api2/invoice_payments', Mockery::any());

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'credit-note payments are not yet synced')
                && $context['order_id'] === 'return-order-uuid-001'
                && $context['payments_count'] === 1;
        });

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

it('does not double-emit on duplicate webhook for the same return', function () {
    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($this->returnOrder);
    $syncOrder->handle($this->returnOrder);

    expect(Invoice::where('foodics_id', $this->returnFoodicsId)->count())->toBe(1);
});

it('revives a failed credit-note row instead of creating a second one', function () {
    Invoice::factory()->creditNote($this->original)->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->returnFoodicsId,
        'foodics_reference' => '00300',
        'daftra_id' => null,
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::any())
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);

    expect(Invoice::where('foodics_id', $this->returnFoodicsId)->count())->toBe(1);
    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote->status)->toBe(InvoiceSyncStatus::Synced);
    expect($creditNote->daftra_id)->toBe(55555);
});

it('does not create a credit note row when original is not synced', function () {
    $this->original->update(['status' => InvoiceSyncStatus::Pending]);

    $this->app->instance(DaftraApiClient::class, Mockery::mock(DaftraApiClient::class));
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    try {
        $this->app->make(SyncOrder::class)->handle($this->returnOrder);
    } catch (OriginalInvoiceNotSyncedException) {
        // expected
    }

    $creditNote = Invoice::where('foodics_id', $this->returnFoodicsId)->first();
    expect($creditNote)->toBeNull();
});

it('emits combo products on credit notes for returned orders', function () {
    $this->returnOrder['products'] = [];
    $this->returnOrder['combos'] = [
        [
            'id' => 'combo-1',
            'products' => [
                [
                    'id' => 'cp-1',
                    'quantity' => 1,
                    'unit_price' => 25,
                    'discount_amount' => 0,
                    'product' => ['id' => 'cp-1', 'name' => 'Medium Burger', 'sku' => 'MB1', 'price' => 25, 'cost' => null, 'is_active' => true, 'description' => '', 'barcode' => null],
                    'taxes' => [],
                ],
                [
                    'id' => 'cp-2',
                    'quantity' => 1,
                    'unit_price' => 6,
                    'discount_amount' => 0,
                    'product' => ['id' => 'cp-2', 'name' => 'Fries', 'sku' => 'FR1', 'price' => 6, 'cost' => null, 'is_active' => true, 'description' => '', 'barcode' => null],
                    'taxes' => [],
                ],
            ],
            'discount_type' => null,
            'discount_amount' => 0,
            'quantity' => 1,
        ],
    ];

    $mockClient = setupDaftraMockForCreditNote();
    $mockClient->shouldReceive('post')
        ->with('/api2/credit_notes', Mockery::on(function (array $payload) {
            expect($payload['InvoiceItem'])->toHaveCount(2);
            expect($payload['InvoiceItem'][0]['item'])->toBe('Medium Burger');
            expect($payload['InvoiceItem'][1]['item'])->toBe('Fries');

            return true;
        }))
        ->once()
        ->andReturn(mockReturnHttpResponse(successful: true, status: 200, json: ['id' => 55555]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->returnOrder);
});

function mockReturnHttpResponse(bool $successful, int $status, array $json): object
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
