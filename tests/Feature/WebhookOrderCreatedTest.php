<?php

use App\Enums\WebhookStatus;
use App\Jobs\ProcessWebhookLogJob;
use App\Models\ProviderToken;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use App\Webhooks\Handlers\OrderCreatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('stores user_id on webhook_log when business reference matches a user', function () {
    $user = User::factory()->create(['foodics_ref' => '154543']);

    Queue::fake();

    $response = $this->postJson('/webhooks/foodics', [
        'event' => 'order.created',
        'timestamp' => 1603798700,
        'business' => ['name' => 'Happy Meal', 'reference' => 154543],
        'order' => ['id' => 'order-123', 'reference' => '00292'],
    ]);

    $response->assertOk();

    $log = WebhookLog::query()->first();
    expect($log->user_id)->toBe($user->id);
    expect($log->event)->toBe('order.created');
    expect($log->order_id)->toBe('order-123');
    expect($log->business_reference)->toBe(154543);
});

it('does not create webhook_log when business reference does not match a user', function () {
    $response = $this->postJson('/webhooks/foodics', [
        'event' => 'order.created',
        'timestamp' => 1603798700,
        'business' => ['name' => 'Unknown', 'reference' => 999999],
        'order' => ['id' => 'order-456', 'reference' => '00100'],
    ]);

    $response->assertOk();
    expect(WebhookLog::query()->count())->toBe(0);
});

it('does not create webhook_log when business reference is missing', function () {
    $response = $this->postJson('/webhooks/foodics', [
        'event' => 'order.created',
        'timestamp' => 1603798700,
        'order' => ['id' => 'order-789'],
    ]);

    $response->assertOk();
    expect(WebhookLog::query()->count())->toBe(0);
});

it('returns 200 even when event is missing and does not create log', function () {
    User::factory()->create(['foodics_ref' => '123']);

    $response = $this->postJson('/webhooks/foodics', [
        'timestamp' => 1603798700,
        'business' => ['name' => 'Test', 'reference' => 123],
    ]);

    $response->assertOk();
    expect(WebhookLog::query()->count())->toBe(0);
});

it('dispatches ProcessWebhookLogJob after logging', function () {
    Queue::fake();

    User::factory()->create(['foodics_ref' => '154543']);

    $this->postJson('/webhooks/foodics', [
        'event' => 'order.created',
        'timestamp' => 1603798700,
        'business' => ['name' => 'Happy Meal', 'reference' => 154543],
        'order' => ['id' => 'order-123', 'reference' => '00292'],
    ]);

    Queue::assertPushed(ProcessWebhookLogJob::class);
});

it('OrderCreatedHandler skips when user is null', function () {
    $webhookLog = WebhookLog::query()->create([
        'user_id' => null,
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 999999,
        'order_id' => 'order-123',
    ]);

    $handler = new OrderCreatedHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCreatedHandler skips when user has no Foodics token', function () {
    $user = User::factory()->create();

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $handler = new OrderCreatedHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCreatedHandler skips when order ID is missing from payload', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => []],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
    ]);

    $handler = new OrderCreatedHandler;

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))->not->toThrow(Exception::class);
});

it('OrderCreatedHandler throws when Foodics API returns empty order', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->with('order-123')->andReturn([]);

    $handler = Mockery::mock(OrderCreatedHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);

    expect(fn () => $handler->handle($webhookLog, $webhookLog->payload))
        ->toThrow(RuntimeException::class, 'Failed to fetch order order-123 from Foodics API');
});

it('OrderCreatedHandler fetches order and delegates to SyncOrder', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $orderData = [
        'id' => 'order-123',
        'reference' => '00292',
        'business_date' => '2019-11-28',
        'total_price' => 24.15,
        'discount_amount' => 5,
        'products' => [],
        'payments' => [],
        'charges' => [],
    ];

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->with('order-123')->andReturn($orderData);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldReceive('handle')->with($orderData)->once();
    app()->instance(SyncOrder::class, $syncOrderMock);

    $handler = Mockery::mock(OrderCreatedHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);

    $handler->handle($webhookLog, $webhookLog->payload);

    expect(Context::get('user')->id)->toBe($user->id);
});

it('ProcessWebhookLogJob sets user context before processing', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->andReturn(['id' => 'order-123', 'reference' => '00292', 'business_date' => '2019-11-28', 'total_price' => 0, 'products' => [], 'payments' => [], 'charges' => []]);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldReceive('handle')->once();
    app()->instance(SyncOrder::class, $syncOrderMock);

    $handler = Mockery::mock(OrderCreatedHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);
    app()->instance(OrderCreatedHandler::class, $handler);

    (new ProcessWebhookLogJob($webhookLog->id))->handle();

    expect(Context::get('user')->id)->toBe($user->id);

    $webhookLog->refresh();
    expect($webhookLog->status)->toBe(WebhookStatus::Processed);
    expect($webhookLog->processed_at)->not->toBeNull();
});

it('marks webhook as processed after successful handling', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrder')->andReturn(['id' => 'order-123', 'reference' => '00292', 'business_date' => '2019-11-28', 'total_price' => 0, 'products' => [], 'payments' => [], 'charges' => []]);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldReceive('handle')->once();
    app()->instance(SyncOrder::class, $syncOrderMock);

    $handler = Mockery::mock(OrderCreatedHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('resolveOrderService')->andReturn($orderServiceMock);
    app()->instance(OrderCreatedHandler::class, $handler);

    (new ProcessWebhookLogJob($webhookLog->id))->handle();

    expect($webhookLog->fresh()->status)->toBe(WebhookStatus::Processed);
});

it('marks webhook as failed when handler throws after all retries', function () {
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
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Pending,
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $job = new ProcessWebhookLogJob($webhookLog->id);
    $job->failed(new RuntimeException('Failed to fetch order order-123 from Foodics API'));

    expect($webhookLog->fresh()->status)->toBe(WebhookStatus::Failed);
    expect($webhookLog->fresh()->error_message)->toBe('Failed to fetch order order-123 from Foodics API');
});

it('does not re-process an already processed webhook', function () {
    $user = User::factory()->create();

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user->id,
        'event' => 'order.created',
        'timestamp' => now(),
        'payload' => ['order' => ['id' => 'order-123']],
        'status' => WebhookStatus::Processed,
        'processed_at' => now(),
        'business_reference' => 154543,
        'order_id' => 'order-123',
    ]);

    $syncOrderMock = Mockery::mock(SyncOrder::class);
    $syncOrderMock->shouldNotReceive('handle');
    app()->instance(SyncOrder::class, $syncOrderMock);

    (new ProcessWebhookLogJob($webhookLog->id))->handle();

    expect($webhookLog->fresh()->status)->toBe(WebhookStatus::Processed);
});

afterEach(function () {
    Context::forget('user');
    Mockery::close();
});
