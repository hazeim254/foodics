# Order Cancelled Webhook Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `order.cancelled` webhook handler so that when Foodics fires an `order.cancelled` event, the system fetches the return order from Foodics and creates a credit note in Daftra via the existing `SyncOrder` → `SyncCreditNote` pipeline.

**Architecture:** Foodics creates a separate return order (new ID, status 5, with `original_order.id`) when an order is cancelled. The `order.cancelled` webhook payload contains the return order's ID. The handler fetches this order from Foodics API and delegates to `SyncOrder::handle()`, which already routes status 5 orders to `SyncCreditNote`. This is the same pattern as `OrderCreatedHandler`.

**Tech Stack:** Laravel 12, Pest 4, Mockery

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `app/Webhooks/Handlers/OrderCancelledHandler.php` | Modify | Fetch return order from Foodics, delegate to `SyncOrder` |
| `tests/Feature/WebhookOrderCancelledTest.php` | Create | Test handler behavior (edge cases + happy path) |

No new classes, migrations, or services needed. The entire feature reuses the existing `SyncOrder::handle()` → `SyncCreditNote` pipeline.

---

### Task 1: Write handler tests

**Files:**
- Create: `tests/Feature/WebhookOrderCancelledTest.php`

- [ ] **Step 1: Create the test file with all test cases**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/WebhookOrderCancelledTest.php`
Expected: FAIL — `OrderCancelledHandler` is still a stub that doesn't fetch orders or delegate to `SyncOrder`. The "fetches return order and delegates" and "throws when Foodics API returns empty" tests will fail because the stub just logs and returns.

---

### Task 2: Implement OrderCancelledHandler

**Files:**
- Modify: `app/Webhooks/Handlers/OrderCancelledHandler.php`

- [ ] **Step 1: Replace the stub with the real implementation**

Replace the entire file content of `app/Webhooks/Handlers/OrderCancelledHandler.php` with:

```php
<?php

namespace App\Webhooks\Handlers;

use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class OrderCancelledHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        $orderId = data_get($payload, 'order.id');

        if (! $orderId) {
            Log::warning('OrderCancelledHandler: Missing order ID in webhook payload', [
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        $user = $webhookLog->user;

        if (! $user) {
            Log::warning('OrderCancelledHandler: No user associated with webhook', [
                'webhook_log_id' => $webhookLog->id,
                'business_reference' => $webhookLog->business_reference,
            ]);

            return;
        }

        if (! $user->getFoodicsToken()) {
            Log::warning('OrderCancelledHandler: User has no Foodics token', [
                'user_id' => $user->id,
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        Context::add('user', $user);

        $order = $this->resolveOrderService($user)->getOrder($orderId);

        if (empty($order)) {
            throw new \RuntimeException("Failed to fetch order {$orderId} from Foodics API");
        }

        app(SyncOrder::class)->handle($order);
    }

    protected function resolveOrderService(User $user): OrderService
    {
        return new OrderService(new FoodicsApiClient($user));
    }
}
```

- [ ] **Step 2: Run the handler tests**

Run: `php artisan test --compact tests/Feature/WebhookOrderCancelledTest.php`
Expected: All 6 tests PASS.

---

### Task 3: Run Pint and existing tests

- [ ] **Step 1: Run Pint on dirty files**

Run: `vendor/bin/pint --dirty --format agent`
Expected: No formatting issues.

- [ ] **Step 2: Run the existing webhook tests to ensure no regressions**

Run: `php artisan test --compact tests/Feature/WebhookOrderCreatedTest.php`
Expected: All existing tests PASS.

- [ ] **Step 3: Run the credit note sync tests to ensure no regressions**

Run: `php artisan test --compact tests/Feature/Services/SyncOrderReturnTest.php`
Expected: All existing tests PASS.

---

### Task 4: Commit

- [ ] **Step 1: Stage and commit**

```bash
git add app/Webhooks/Handlers/OrderCancelledHandler.php tests/Feature/WebhookOrderCancelledTest.php
git commit -m "feat: implement order.cancelled webhook handler

When Foodics fires order.cancelled, fetch the return order (status 5)
from Foodics API and delegate to SyncOrder which routes it through
SyncCreditNote to create a credit note in Daftra."
```
