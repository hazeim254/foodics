<?php

use App\Enums\InvoiceSyncStatus;
use App\Exceptions\DaftraInvoiceCreationFailedException;
use App\Exceptions\DaftraPaymentCreationFailedException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->order = json_decode(file_get_contents(base_path('json-stubs/foodics/get-order.json')), true)['order'];

    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));
});

function stubHappyPathDaftraCalls(MockInterface $mockClient, int $daftraInvoiceId = 12345, bool $daftraInvoiceAlreadyExists = false): void
{
    $notFound = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    if ($daftraInvoiceAlreadyExists) {
        $mockClient->shouldReceive('get')
            ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
            ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
                'data' => [['Invoice' => ['id' => $daftraInvoiceId, 'po_number' => 'foo']]],
            ]));
    } else {
        $mockClient->shouldReceive('get')
            ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field'])))
            ->andReturn($notFound);
    }

    $mockClient->shouldReceive('get')->with('/api2/products', Mockery::any())->andReturn($notFound);
    $mockClient->shouldReceive('post')->with('/api2/products', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 67890]));

    $mockClient->shouldReceive('get')->with('/v2/api/entity/client/list', Mockery::any())->andReturn($notFound);
    $mockClient->shouldReceive('post')->with('/api2/clients.json', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]));

    $mockClient->shouldReceive('get')->with('/api2/taxes.json', Mockery::any())->andReturn($notFound);
    $mockClient->shouldReceive('post')->with('/api2/taxes.json', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 54321]));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['id' => 424242, 'label' => 'Card', 'payment_gateway' => 'card']],
        ]));
}

it('writes a pending row scoped to the current user before Daftra work starts', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient, daftraInvoiceAlreadyExists: false);

    $pendingCaptured = null;
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function () use (&$pendingCaptured) {
            $pendingCaptured = Invoice::query()->first();

            return true;
        }))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));
    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect($pendingCaptured)->not->toBeNull();
    expect($pendingCaptured->status)->toBe(InvoiceSyncStatus::Pending);
    expect($pendingCaptured->daftra_id)->toBeNull();
    expect($pendingCaptured->user_id)->toBe($this->user->id);
    expect($pendingCaptured->foodics_id)->toBe($this->order['id']);
    expect($pendingCaptured->foodics_reference)->toBe($this->order['reference']);

    $final = Invoice::query()->first();
    expect($final->status)->toBe(InvoiceSyncStatus::Synced);
    expect($final->daftra_id)->toBe(12345);
});

it('blocks a duplicate sync when a pending row for the same foodics_id exists', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->order['id'],
        'foodics_reference' => 'other-ref',
        'daftra_id' => null,
        'status' => InvoiceSyncStatus::Pending,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');
    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect(Invoice::query()->count())->toBe(1);
});

it('blocks a duplicate sync when a pending row for the same foodics_reference exists', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => 'different-id',
        'foodics_reference' => $this->order['reference'],
        'daftra_id' => null,
        'status' => InvoiceSyncStatus::Pending,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');
    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect(Invoice::query()->count())->toBe(1);
});

it('revives a previously failed row into a single pending/synced row on retry', function () {
    $failed = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->order['id'],
        'foodics_reference' => $this->order['reference'],
        'daftra_id' => null,
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient);
    $mockClient->shouldReceive('post')->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));
    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));
    $mockClient->shouldReceive('post')->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect(Invoice::query()->count())->toBe(1);
    $row = Invoice::query()->first();
    expect($row->id)->toBe($failed->id);
    expect($row->status)->toBe(InvoiceSyncStatus::Synced);
    expect($row->daftra_id)->toBe(12345);
});

it('does not block a sync for another user with the same foodics_id', function () {
    $otherUser = User::factory()->create();
    Invoice::factory()->create([
        'user_id' => $otherUser->id,
        'foodics_id' => $this->order['id'],
        'foodics_reference' => $this->order['reference'],
        'status' => InvoiceSyncStatus::Synced,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient);
    $mockClient->shouldReceive('post')->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));
    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));
    $mockClient->shouldReceive('post')->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect(Invoice::query()->count())->toBe(2);
});

it('marks the row failed when Daftra invoice creation fails', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient, daftraInvoiceAlreadyExists: false);

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: false, status: 422, json: ['error' => 'bad']));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    expect(fn () => $this->app->make(SyncOrder::class)->handle($this->order))
        ->toThrow(DaftraInvoiceCreationFailedException::class);

    $invoice = Invoice::query()->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Failed);
    expect($invoice->daftra_id)->toBeNull();
});

it('persists daftra_id even when a later payment post fails and marks row failed', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient, daftraInvoiceAlreadyExists: false);

    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: false, status: 500, json: ['error' => 'boom']));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    expect(fn () => $this->app->make(SyncOrder::class)->handle($this->order))
        ->toThrow(DaftraPaymentCreationFailedException::class);

    $invoice = Invoice::query()->first();
    expect($invoice->status)->toBe(InvoiceSyncStatus::Failed);
    expect($invoice->daftra_id)->toBe(12345);
});

it('reuses an existing Daftra invoice id from the local pending row on retry', function () {
    $pending = Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->order['id'],
        'foodics_reference' => $this->order['reference'].'-stale',
        'daftra_id' => 99999,
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient);
    $mockClient->shouldNotReceive('post')->with('/api2/invoices', Mockery::any());

    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', ['filter[invoice_id]' => 99999, 'limit' => 50])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['InvoicePayment' => ['id' => 5, 'invoice_id' => 99999]]],
        ]));

    $mockClient->shouldNotReceive('post')->with('/api2/invoice_payments', Mockery::any());

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    $final = Invoice::query()->where('status', InvoiceSyncStatus::Synced)->first();
    expect($final)->not->toBeNull();
    expect($final->daftra_id)->toBe(99999);
});

it('adopts an existing Daftra invoice id when local row has no daftra_id yet', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient, daftraInvoiceId: 77777, daftraInvoiceAlreadyExists: true);

    $mockClient->shouldNotReceive('post')->with('/api2/invoices', Mockery::any());

    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', ['filter[invoice_id]' => 77777, 'limit' => 50])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    $invoice = Invoice::query()->first();
    expect($invoice->daftra_id)->toBe(77777);
    expect($invoice->status)->toBe(InvoiceSyncStatus::Synced);
});

it('skips payment posting entirely when Daftra already has at least one payment', function () {
    Invoice::factory()->create([
        'user_id' => $this->user->id,
        'foodics_id' => $this->order['id'],
        'foodics_reference' => $this->order['reference'].'-stale',
        'daftra_id' => 33333,
        'status' => InvoiceSyncStatus::Failed,
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubHappyPathDaftraCalls($mockClient);
    $mockClient->shouldNotReceive('post')->with('/api2/invoices', Mockery::any());

    $mockClient->shouldReceive('get')
        ->with('/api2/invoice_payments', ['filter[invoice_id]' => 33333, 'limit' => 50])
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [['InvoicePayment' => ['id' => 5]]],
        ]));

    $mockClient->shouldNotReceive('post')->with('/api2/invoice_payments', Mockery::any());

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->app->make(SyncOrder::class)->handle($this->order);

    $final = Invoice::query()->where('status', InvoiceSyncStatus::Synced)->first();
    expect($final->daftra_id)->toBe(33333);
});
