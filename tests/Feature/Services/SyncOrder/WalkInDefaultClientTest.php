<?php

use App\Enums\SettingKey;
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
});

function stubDaftraSideEffectsForWalkIn(MockInterface $mockClient): void
{
    $notFound = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $created = fn (int $id) => createMockHttpResponse(successful: true, status: 202, json: ['id' => $id]);

    $existingCardGateway = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            [
                'id' => 99999,
                'label' => 'Card',
                'payment_gateway' => 'card',
            ],
        ],
    ]);

    $mockClient->shouldReceive('get')
        ->with('/api2/invoices', Mockery::on(fn (array $args) => isset($args['custom_field']) && isset($args['custom_field_label'])))
        ->twice()
        ->andReturn($notFound);

    $mockClient->shouldReceive('get')
        ->with('/api2/products', Mockery::any())
        ->andReturn($notFound);

    $mockClient->shouldReceive('post')
        ->with('/api2/products', Mockery::any())
        ->andReturn($created(67890));

    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->andReturn($notFound);

    $mockClient->shouldReceive('post')
        ->with('/api2/taxes.json', Mockery::any())
        ->andReturn($created(54321));

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($existingCardGateway);

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/invoice_payment/list', Mockery::on(fn (array $args) => isset($args['filter[invoice_id]']) && $args['filter[invoice_id]'] === 12345))
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 1]));
}

it('uses the per-user default client setting when the order has no customer', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '77777');

    unset($this->order['customer']);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubDaftraSideEffectsForWalkIn($mockClient);

    $mockClient->shouldReceive('get')
        ->with('/api2/clients', Mockery::any())
        ->never();
    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->never();

    $capturedClientId = null;
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) use (&$capturedClientId) {
            $capturedClientId = $payload['Invoice']['client_id'] ?? null;

            return true;
        }))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect($capturedClientId)->toBe(77777);
});

it('sends a null client_id when the order has no customer and the setting is unset', function () {
    unset($this->order['customer']);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubDaftraSideEffectsForWalkIn($mockClient);

    $capturedPayload = null;
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        }))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect($capturedPayload['Invoice'])->toHaveKey('client_id');
    expect($capturedPayload['Invoice']['client_id'])->toBeNull();
});

it('ignores the default client setting when the order already has a customer', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '77777');

    $mockClient = Mockery::mock(DaftraApiClient::class);
    stubDaftraSideEffectsForWalkIn($mockClient);

    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/client/list', Mockery::any())
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $mockClient->shouldReceive('post')
        ->with('/api2/clients.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 202, json: ['id' => 11111]));

    $capturedClientId = null;
    $mockClient->shouldReceive('post')
        ->with('/api2/invoices', Mockery::on(function (array $payload) use (&$capturedClientId) {
            $capturedClientId = $payload['Invoice']['client_id'] ?? null;

            return true;
        }))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['id' => 12345]));

    $this->app->instance(DaftraApiClient::class, $mockClient);
    $this->app->instance(FoodicsApiClient::class, Mockery::mock(FoodicsApiClient::class));

    $this->app->make(SyncOrder::class)->handle($this->order);

    expect($capturedClientId)->toBe(11111);
});
