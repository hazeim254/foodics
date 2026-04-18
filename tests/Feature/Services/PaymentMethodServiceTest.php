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

it('resolves payment method slug from local cache', function () {
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
        'metadata' => ['name' => 'Card', 'code' => 'Card', 'payment_gateway' => 'card'],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldNotReceive('get');
    $mockClient->shouldNotReceive('post');

    $service = new PaymentMethodService($mockClient);
    $slug = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('card');
});

it('falls back to slugified name when cache lacks payment_gateway metadata', function () {
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
    $slug = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('card');
});

it('searches daftra when not cached and creates mapping', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            [
                'id' => 67890,
                'label' => 'Card',
                'payment_gateway' => 'card',
            ],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $slug = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('card');
    $mapping = EntityMapping::where('foodics_id', '8df57bde')->where('daftra_id', 67890)->first();
    expect($mapping)->not->toBeNull();
    expect($mapping->metadata['payment_gateway'])->toBe('card');
});

it('prefetches gateways once so multiple resolutions reuse the same list', function () {
    $first = ['id' => 'pm-one', 'name' => 'Card', 'code' => 'Card'];
    $second = ['id' => 'pm-two', 'name' => 'Card', 'code' => 'Card'];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            [
                'id' => 67890,
                'label' => 'Card',
                'payment_gateway' => 'card',
            ],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $service->beginPaymentMethodBatch();
    expect($service->resolvePaymentMethod($first))->toBe('card');
    expect($service->resolvePaymentMethod($second))->toBe('card');
    $service->endPaymentMethodBatch();

    expect(EntityMapping::where('type', 'payment_method')->count())->toBe(2);
});

it('does not post when list already contains matching payment_gateway slug', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Card',
        'code' => 'Card',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            [
                'id' => 67890,
                'label' => 'Renamed label',
                'payment_gateway' => 'card',
            ],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsResponse);
    $mockClient->shouldNotReceive('post');

    $service = new PaymentMethodService($mockClient);
    $slug = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('card');
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
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsEmptyResponse);

    $mockClient->shouldReceive('post')
        ->with('/v2/api/entity/site_payment_gateway', Mockery::on(function (array $payload) {
            expect($payload['payment_gateway'])->toBe('card');
            expect($payload['slug'])->toBe('card');
            expect($payload['label'])->toBe('Card');
            expect($payload['manually_added'])->toBe(1);
            expect($payload['active'])->toBe(1);

            return true;
        }))
        ->once()
        ->andReturn($paymentMethodCreateResponse);

    $service = new PaymentMethodService($mockClient);
    $slug = $service->resolvePaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('card');
    expect(EntityMapping::where('foodics_id', '8df57bde')->where('daftra_id', 99999)->exists())->toBeTrue();
    expect(EntityMapping::where('foodics_id', '8df57bde')->first()->metadata['payment_gateway'])->toBe('card');
});

it('finds payment method in daftra by payment_gateway slug', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Cash',
        'code' => 'Cash',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            [
                'id' => 54321,
                'label' => 'Cash drawer',
                'payment_gateway' => 'cash',
            ],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $found = $service->getPaymentMethod($foodicsPaymentMethod);

    expect($found)->toBe(['id' => 54321, 'slug' => 'cash']);
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
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsEmptyResponse);

    $service = new PaymentMethodService($mockClient);
    $found = $service->getPaymentMethod($foodicsPaymentMethod);

    expect($found)->toBeNull();
});

it('returns null when list row is missing payment_gateway', function () {
    $foodicsPaymentMethod = [
        'id' => '8df57bde',
        'name' => 'Cash',
        'code' => 'Cash',
    ];

    $paymentMethodsResponse = createMockHttpResponse(successful: true, status: 200, json: [
        'data' => [
            ['id' => 54321, 'label' => 'Cash'],
        ],
    ]);

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v2/api/entity/site_payment_gateway/list?per_page=100')
        ->once()
        ->andReturn($paymentMethodsResponse);

    $service = new PaymentMethodService($mockClient);
    $found = $service->getPaymentMethod($foodicsPaymentMethod);

    expect($found)->toBeNull();
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
            expect($payload['payment_gateway'])->toBe('digital_wallet');
            expect($payload['slug'])->toBe('digital_wallet');
            expect($payload['label'])->toBe('Digital Wallet');
            expect($payload['manually_added'])->toBe(1);
            expect($payload['active'])->toBe(1);

            return true;
        }))
        ->once()
        ->andReturn($paymentMethodCreateResponse);

    $service = new PaymentMethodService($mockClient);
    $slug = $service->createPaymentMethod($foodicsPaymentMethod);

    expect($slug)->toBe('digital_wallet');
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
    expect($mapping->metadata)->toBe([
        'name' => 'Card',
        'code' => 'Card',
        'payment_gateway' => 'card',
    ]);
});
