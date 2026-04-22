# 022 — Webhook Order Created: Process Foodics Webhooks & Sync Orders

## Overview

Implement the `order.created` webhook handler so that when Foodics sends an `order.created` webhook, the system resolves the user from the business reference, logs the webhook with a `user_id`, dispatches a job, and the job fetches the full order from the Foodics API and passes it to `SyncOrder`.

## Context

- The webhook infrastructure already exists: `POST /webhooks/foodics` → `WebhooksController` → `WebhookLogService::log()` → `WebhookLog` record → `ProcessWebhookLogJob` → event-based handler dispatch.
- `FoodicsWebhook` middleware validates signature (TODO) and checks that `business.reference` maps to a `User` via `foodics_ref`.
- `ProcessWebhookLogJob` already dispatches to `OrderCreatedHandler` for `order.created` events.
- `OrderCreatedHandler` is currently a placeholder that only logs.
- `Foodics\OrderService::getOrder($orderId)` fetches a single order from the Foodics API with includes.
- `SyncOrder::handle($order)` handles the full order-to-invoice sync flow using the authenticated user from `Context::get('user')`.
- The `webhook_logs` table currently has `business_reference`, `order_id`, `order_reference` columns but no `user_id`.
- Per Foodics docs: webhook payloads contain `business.reference` (maps to `users.foodics_ref`), `event`, `timestamp`, and an inline order/customer/entity object. The API expects an immediate 2xx response; processing must be async. Foodics retries up to 2 times on failure, and blocks URLs returning non-2xx for 100 requests/minute.

## Decisions

| Concern | Decision |
|----------|-----------|
| User resolution | Add `user_id` FK to `webhook_logs`. Resolve user from `foodics_ref` in the middleware (already done) and store in the log during `WebhookLogService::log()` |
| Order data source | Fetch fresh order from Foodics API via `OrderService::getOrder()` rather than using inline payload. Guarantees latest state with all includes |
| Context propagation | Set `Context::add('user', $user)` inside `OrderCreatedHandler` so `SyncOrder` can access the user via `Context::get('user')` as it expects |
| FoodicsApiClient construction | `OrderService` depends on `FoodicsApiClient` which takes a `User` in its constructor. The handler will resolve the user and construct the service manually |
| Handler pattern | Handlers are currently instantiated without DI (`new OrderCreatedHandler`). Keep this pattern for now and resolve dependencies inside the handler |

## Requirements

### 1. Migration — Add `user_id` to `webhook_logs`

Add a migration that:

- Adds `user_id` (nullable unsignedBigInteger, after `id`) to `webhook_logs`.
- Adds a foreign key constraint: `user_id` → `users.id` ON DELETE SET NULL.
- Adds an index on `user_id`.

The column is nullable so that existing rows and webhooks for unknown businesses don't break.

### 2. Model — `WebhookLog`

Update the `WebhookLog` model:

- Add `user_id` to `$fillable`.
- Add a `user()` relationship:

```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

### 3. Service — `WebhookLogService`

Update `WebhookLogService::log()` to:

- Resolve the user from `business.reference` using `User::query()->where('foodics_ref', $businessReference)->first()`.
- Store the `user_id` on the `WebhookLog` record.
- Pass the resolved user into the dispatched `ProcessWebhookLogJob` so the job doesn't need to re-resolve.

```php
public function log(Request $request): WebhookLog
{
    $payload = $request->all();

    if (! isset($payload['event'])) {
        throw new \Exception('Missing required field: event');
    }

    if (! isset($payload['timestamp'])) {
        throw new \Exception('Missing required field: timestamp');
    }

    $businessReference = data_get($payload, 'business.reference');
    $orderId = data_get($payload, 'order.id');
    $orderReference = data_get($payload, 'order.reference');

    $user = $businessReference
        ? User::query()->where('foodics_ref', (string) $businessReference)->first()
        : null;

    $timestamp = is_numeric($payload['timestamp'])
        ? Carbon::createFromTimestamp($payload['timestamp'])
        : Carbon::parse($payload['timestamp']);

    $webhookLog = WebhookLog::query()->create([
        'user_id' => $user?->id,
        'event' => $payload['event'],
        'timestamp' => $timestamp,
        'payload' => $payload,
        'signature' => $request->header('X-Signature'),
        'status' => WebhookStatus::Pending,
        'business_reference' => $businessReference ? (int) $businessReference : null,
        'order_id' => $orderId,
        'order_reference' => $orderReference ? (int) $orderReference : null,
    ]);

    ProcessWebhookLogJob::dispatch($webhookLog->id);

    return $webhookLog;
}
```

### 4. Job — `ProcessWebhookLogJob`

Update `ProcessWebhookLogJob` to:

- Accept an optional `User` parameter (or resolve from `WebhookLog->user`).
- Set `Context::add('user', $user)` before processing the webhook handler so handlers have access to the authenticated user context.

```php
public function __construct(public int $webhookLogId)
{
    $this->onQueue('webhooks');
}

public function handle(): void
{
    DB::transaction(function () {
        $webhookLog = WebhookLog::query()
            ->lockForUpdate()
            ->find($this->webhookLogId);

        if (! $webhookLog) {
            Log::warning('WebhookLog not found, job may have been processed already', [
                'webhook_log_id' => $this->webhookLogId,
            ]);

            return;
        }

        if ($webhookLog->status === WebhookStatus::Processed) {
            return;
        }

        if ($webhookLog->status === WebhookStatus::Failed && $this->attempts() >= $this->tries) {
            return;
        }

        // Set user context for handlers
        if ($webhookLog->user) {
            Context::add('user', $webhookLog->user);
        }

        try {
            $this->processWebhook($webhookLog);

            $webhookLog->update([
                'status' => WebhookStatus::Processed,
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('Error processing WebhookLog', [
                'webhook_log_id' => $this->webhookLogId,
                'event' => $webhookLog->event ?? null,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    });
}
```

### 5. Handler — `OrderCreatedHandler`

Replace the placeholder with the real implementation:

```php
class OrderCreatedHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        $orderId = data_get($payload, 'order.id');

        if (! $orderId) {
            Log::warning('OrderCreatedHandler: Missing order ID in webhook payload', [
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        $user = $webhookLog->user;

        if (! $user) {
            Log::warning('OrderCreatedHandler: No user associated with webhook', [
                'webhook_log_id' => $webhookLog->id,
                'business_reference' => $webhookLog->business_reference,
            ]);

            return;
        }

        if (! $user->getFoodicsToken()) {
            Log::warning('OrderCreatedHandler: User has no Foodics token', [
                'user_id' => $user->id,
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        Context::add('user', $user);

        $orderService = new OrderService(new FoodicsApiClient($user));
        $order = $orderService->getOrder($orderId);

        if (empty($order)) {
            throw new \RuntimeException("Failed to fetch order {$orderId} from Foodics API");
        }

        app(SyncOrder::class)->handle($order);
    }
}
```

Notes:
- The handler resolves the user from the `WebhookLog` relationship.
- It constructs `FoodicsApiClient` with the user (which sets up auth headers and token refresh).
- It fetches the full order from Foodics API via `OrderService::getOrder()`.
- It delegates to `SyncOrder::handle()` for the Daftra sync.
- If the order can't be fetched, it throws to trigger the job retry mechanism.

### 6. Update `WebhookLog` builder scopes

Add a `byUser` scope to `WebhookLogQueryBuilder`:

```php
public function byUser(int $userId): self
{
    return $this->where('user_id', $userId);
}
```

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Webhook received for unknown business reference | `user_id` is null on webhook_log; handler logs warning and returns (does not throw, so webhook is marked as processed — no point retrying) |
| User has no Foodics token | Handler logs warning and returns without throwing (processed status, not a transient error) |
| Order not found in Foodics API | Handler throws `RuntimeException`, triggering job retry up to 3 times |
| SyncOrder throws (e.g., Daftra API down) | Handler propagates exception, triggering job retry. WebhookLog stays as Pending until success or final failure |
| Duplicate webhook from Foodics retry | `SyncOrder::skipIfAlreadySynced` handles idempotency — if invoice already Pending/Synced, it returns early |
| `order.updated` or other events | Routed to their existing handlers (`OrderUpdatedHandler`, `UnknownEventHandler`), unchanged |
| Job runs but user was deleted | `user_id` FK SET NULL; handler sees null user, logs warning and returns |

## Files to Create

1. `database/migrations/2026_04_xx_xxxxxx_add_user_id_to_webhook_logs_table.php`

## Files to Modify

1. `app/Models/WebhookLog.php` — add `user_id` to `$fillable`, add `user()` relationship
2. `app/Models/Builders/WebhookLogQueryBuilder.php` — add `byUser()` scope
3. `app/Services/WebhookLogService.php` — resolve user from `foodics_ref` and store `user_id`
4. `app/Jobs/ProcessWebhookLogJob.php` — set user context before processing
5. `app/Webhooks/Handlers/OrderCreatedHandler.php` — implement real logic

## Tests

### `tests/Feature/WebhookOrderCreatedTest.php`

- Webhook with `order.created` event creates a webhook_log with correct `user_id`
- Webhook with unknown business reference creates webhook_log with null `user_id`
- `OrderCreatedHandler` fetches order from Foodics API and calls SyncOrder
- `OrderCreatedHandler` skips when user is null
- `OrderCreatedHandler` skips when user has no Foodics token
- `OrderCreatedHandler` throws when Foodics API returns empty order
- `OrderCreatedHandler` handles SyncOrder exceptions (propagates for retry)
- `ProcessWebhookLogJob` sets user context before handler execution
- Idempotency: duplicate webhook for same order is handled by SyncOrder's duplicate guard

### `tests/Feature/WebhookLogServiceTest.php`

- `log()` resolves user from business reference and stores user_id
- `log()` stores null user_id when business reference doesn't match a user
- `log()` throws when event field is missing
- `log()` throws when timestamp field is missing

## Tasks

- [ ] Create migration to add `user_id` to `webhook_logs` table
- [ ] Update `app/Models/WebhookLog.php` — add `user_id` to `$fillable`, add `user()` relationship
- [ ] Update `app/Models/Builders/WebhookLogQueryBuilder.php` — add `byUser()` scope
- [ ] Update `app/Services/WebhookLogService.php` — resolve user, store `user_id`
- [ ] Update `app/Jobs/ProcessWebhookLogJob.php` — set user context
- [ ] Update `app/Webhooks/Handlers/OrderCreatedHandler.php` — implement real logic
- [ ] Write feature tests
- [ ] Run `vendor/bin/pint --dirty --format agent`
- [ ] Run tests to verify everything passes

## Out of Scope

- Signature verification (already TODO in `FoodicsWebhook` middleware)
- `order.updated` and `order.cancelled` handler implementations
- Menu webhooks (`menu.updated`)
- Customer webhooks (`customer.created`)
- Webhook admin UI or retry UI
- Rate limiting on the webhook endpoint
- Webhook delivery status notifications to the user
