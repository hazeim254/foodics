<?php

use App\Models\EntityMapping;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\PaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('resolves payment method id from local cache', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'payment_method',
        'foodics_id' => '8df57bde',
        'daftra_id' => 12345,
        'metadata' => ['name' => 'Card', 'code' => 'Card'],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBe(12345);
});

it('searches daftra when not cached and creates mapping', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['SitePaymentGateway' => ['id' => 67890, 'label' => 'Card']],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBe(67890);
    expect(EntityMapping::where('foodics_id', '8df57bde')->where('daftra_id', 67890)->exists())->toBeTrue();
});

it('creates payment method in daftra when not found and persists mapping', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    $paymentMethodsEmptyResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);
    $paymentMethodCreateResponse = createMockHttpResponse(successful: true, status: 201, json: ['id' => 99999]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentMethodsEmptyResponse);

    $mockClient->shouldReceive('post')
        ->with('/v2/api/entity/site_payment_gateway', Mockery::on(function (array $payload) {
            expect($payload['SitePaymentGateway']['payment_gateway'])->toBe('card');
            expect($payload['SitePaymentGateway']['label'])->toBe('Card');
            expect($payload['SitePaymentGateway']['manually_added'])->toBe(1);
            expect($payload['SitePaymentGateway']['active'])->toBe(1);

            return true;
        }))
        ->once()
        ->andReturn($paymentMethodCreateResponse);

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBe(99999);
    expect(EntityMapping::where('foodics_id', '8df57bde')->where('daftra_id', 99999)->exists())->toBeTrue();
});

it('searches daftra by label when getting payment method', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Cash',
        'code' => 'Cash',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['SitePaymentGateway' => ['id' => 54321, 'label' => 'Cash']],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->getPaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBe(54321);
});

it('returns null when payment method not found in daftra', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Wallet',
        'code' => 'Wallet',
    ];

    $paymentMethodsEmptyResponse = createMockHttpResponse(successful: true, status: 200, json: ['data' => []]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list')
        ->once()
        ->andReturn($paymentMethodsEmptyResponse);

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->getPaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBeNull();
});

it('creates payment method with correct payload', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Digital Wallet',
        'code' => 'wallet',
    ];

    $paymentMethodCreateResponse = createMockHttpResponse(successful: true, status: 201, json: ['id' => 11111]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('post')
        ->with('/v2/api/entity/site_payment_gateway', Mockery::on(function (array $payload) {
            expect($payload['SitePaymentGateway']['payment_gateway'])->toBe('digital_wallet');
            expect($payload['SitePaymentGateway']['label'])->toBe('Digital Wallet');
            expect($payload['SitePaymentGateway']['manually_added'])->toBe(1);
            expect($payload['SitePaymentGateway']['active'])->toBe(1);

            return true;
        }))
        ->once()
        ->andReturn($paymentMethodCreateResponse);

    $service = new PaymentMethodService($mockClient);
    $daftraId = $service->createPaymentMethod($foodicsPaymentMethod);

    expect($daftraId)->toBe(11111);
});

it('persists payment method mapping with correct metadata', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    $mockClient = Mockery::mock(DaftraApiClient::class);

    $service = new PaymentMethodService($mockClient);
    $service->persistPaymentMethod($this->user->id, '8df57bde', 99999, $foodicsPaymentMethod);

    $mapping = EntityMapping::where('foodics_id', '8df57bde')->where('daftra_id', 99999)->first();
    expect($mapping)->not->toBeNull();
    expect($mapping->type)->toBe('payment_method');
    expect($mapping->metadata)->toBe(['name' => 'Card', 'code' => 'Card']);
});
