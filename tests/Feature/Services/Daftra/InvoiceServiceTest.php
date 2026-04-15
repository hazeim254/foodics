<?php

use App\Exceptions\DaftraInvoiceCreationFailedException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);

    $this->mockClient = Mockery::mock(DaftraApiClient::class);
    $this->service = new InvoiceService($this->mockClient);
});

it('gets invoice by foodics ID from Daftra', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['Invoice' => ['id' => 99, 'po_number' => 'order-1']],
        ],
    ]);

    $this->mockClient->shouldReceive('get')
        ->with('/api2/invoices', ['custom_field' => 'order-1', 'custom_field_label' => 'Foodics ID'])
        ->once()
        ->andReturn($response);

    $result = $this->service->getInvoice('order-1');

    expect($result)->toBe(['id' => 99, 'po_number' => 'order-1']);
});

it('returns null when invoice not found on Daftra', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    $this->mockClient->shouldReceive('get')
        ->with('/api2/invoices', ['custom_field' => 'order-1', 'custom_field_label' => 'Foodics ID'])
        ->once()
        ->andReturn($response);

    expect($this->service->getInvoice('order-1'))->toBeNull();
});

it('throws on getInvoice API failure', function () {
    $response = createMockHttpResponse(successful: false, status: 500, json: []);

    $this->mockClient->shouldReceive('get')
        ->with('/api2/invoices', ['custom_field' => 'order-1', 'custom_field_label' => 'Foodics ID'])
        ->once()
        ->andReturn($response);

    $this->service->getInvoice('order-1');
})->throws(RuntimeException::class, 'Daftra invoice list request failed');

it('detects existing invoice on Daftra', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['Invoice' => ['id' => 99, 'po_number' => 'order-1']],
        ],
    ]);

    $this->mockClient->shouldReceive('get')->once()->andReturn($response);

    expect($this->service->doesFoodicsInvoiceExistInDaftra('order-1'))->toBeTrue();
});

it('reports non-existing invoice on Daftra', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    $this->mockClient->shouldReceive('get')->once()->andReturn($response);

    expect($this->service->doesFoodicsInvoiceExistInDaftra('order-1'))->toBeFalse();
});

it('creates an invoice and returns the Daftra ID', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]);

    $this->mockClient->shouldReceive('post')
        ->with('/api2/invoices', ['Invoice' => ['po_number' => 'order-1']])
        ->once()
        ->andReturn($response);

    $daftraId = $this->service->createInvoice(['Invoice' => ['po_number' => 'order-1']]);

    expect($daftraId)->toBe(12345);
});

it('throws DaftraInvoiceCreationFailedException on API failure', function () {
    $response = createMockHttpResponse(successful: false, status: 422, json: ['error' => 'bad data']);

    $this->mockClient->shouldReceive('post')->once()->andReturn($response);

    $this->service->createInvoice(['Invoice' => []]);
})->throws(DaftraInvoiceCreationFailedException::class, 'Daftra invoice creation failed: HTTP 422');

it('throws DaftraInvoiceCreationFailedException when response id is missing', function () {
    $response = createMockHttpResponse(successful: true, status: 200, json: ['data' => 'no id here']);

    $this->mockClient->shouldReceive('post')->once()->andReturn($response);

    $this->service->createInvoice(['Invoice' => []]);
})->throws(DaftraInvoiceCreationFailedException::class, 'Daftra invoice creation response missing id');

it('saves mapping between Foodics and Daftra IDs', function () {
    $this->service->saveMapping('foodics-1', 555, 'ref-001');

    $invoice = Invoice::query()->first();
    expect($invoice)
        ->user_id->toBe($this->user->id)
        ->foodics_id->toBe('foodics-1')
        ->daftra_id->toBe(555)
        ->foodics_reference->toBe('ref-001')
        ->status->toBe('synced');
});

it('creates a payment against a Daftra invoice', function () {
    $this->mockClient->shouldReceive('post')
        ->with('/api2/invoices/555/payments', [
            'payment_method' => 1,
            'amount' => 100.0,
            'date' => '2024-01-01',
        ])
        ->once();

    $this->service->createPayment(555, [
        'payment_method' => 1,
        'amount' => 100.0,
        'date' => '2024-01-01',
    ]);
});
