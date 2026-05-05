<?php

use App\Enums\WebhookStatus;
use App\Models\ProviderToken;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use App\Webhooks\Handlers\OrderCancelledHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

it('OrderCancelledHandler skips when user is null', function () {
    $webhookLog = WebhookLog::query()->create([
        'user_id' => null,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 999999,
        'order_id' => 'order-123',
    ]);

    $handler = new OrderCancelledHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCancelledHandler skips when user has no Foodics token', function () {
    $user = User::factory()->create();

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $handler = new OrderCancelledHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCancelledHandler skips when order ID is missing from payload', function () {
    $user = User::factory()->create();
    ProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addYear(),
    ]);

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => []],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
    ]);

    $handler = new OrderCancelledHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCancelledHandler throws when Foodics API returns empty order', function () {
    $user = User::factory()->create();
    ProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addYear(),
    ]);

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'ret-456']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'ret-456',
    ]);

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->with('ret-456')->andReturn([]);

    $handler = Mockery::mock(OrderCancelledHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))
        ->toThrow(RuntimeException::class, 'Failed to fetch order ret-456 from Foodics API');
});

it('OrderCancelledHandler fetches return order and delegates to SyncOrder', function () {
    $user = User::factory()->create();
    ProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addYear(),
    ]);

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'ret-456']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'ret-456',
    ]);

    $returnOrderData = [
        'id' => 'ret-456',
        'reference' => 'R00292',
        'business_date' => '2019-11-28',
        'total_price' => 24.15,
        'discount_amount' => 0,
        'status' => 5,
        'original_order' => ['id' => 'order-123'],
        'products' => [],
        'payments' => [],
        'charges' => [],
    ];

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->with('ret-456')->andReturn($returnOrderData);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldReceive('handle')->with($returnOrderData)->once();
    app()->instance(SyncOrder::class, $syncOrderMock);

    $handler = Mockery::mock(OrderCancelledHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);

    $handler->handle($webhookLog, $webhookLog->payload);

    expect(Context::get('user')->id)->toBe($user->id);
});

it('OrderCancelledHandler sets user context before fetching order', function () {
    $user = User::factory()->create();
    ProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addYear(),
    ]);

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.cancelled',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'ret-789']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'ret-789',
    ]);

    $returnOrderData = [
        'id' => 'ret-789',
        'reference' => 'R00300',
        'business_date' => '2019-11-28',
        'total_price' => 10.00,
        'discount_amount' => 0,
        'status' => 5,
        'original_order' => ['id' => 'order-original'],
        'products' => [],
        'payments' => [],
        'charges' => [],
    ];

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->with('ret-789')->andReturn($returnOrderData);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldReceive('handle')->once();
    app()->instance(SyncOrder::class, $syncOrderMock);

    $handler = Mockery::mock(OrderCancelledHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);

    $handler->handle($webhookLog, $webhookLog->payload);

    expect(Context::get('user'))->not->toBeNull();
    expect(Context::get('user')->id)->toBe($user->id);
});

afterEach(function () {
    Context::forget('user');
    Mockery::close();
});
