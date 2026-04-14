# 006 - Get Single Order

## Overview

Add a `getOrder` method to `Foodics\OrderService` that fetches a single order from the Foodics API by its ID. This will be used by webhook handlers to retrieve full order details when Foodics sends `order.created` / `order.updated` / `order.cancelled` events.

## Context

- **Foodics Get Order endpoint:** `GET /orders/{id}` returns a single order object.
- **Webhook flow:** Foodics sends webhook → `WebhooksController` → `WebhookLogService::log()` stores the payload and dispatches `ProcessWebhookLogJob` → job routes to the appropriate handler (`OrderCreatedHandler`, `OrderUpdatedHandler`, `OrderCancelledHandler`).
- **Webhook payloads contain minimal order data** — the full order (products, charges, payments, customer) must be fetched from the API.
- **`WebhookLog`** already stores `order_id` (the Foodics UUID) and `business_reference` (mapped to `User.foodics_ref`).
- **`FoodicsApiClient`** is scoped to a user via the container binding (spec 004). The user context must be set before resolving the client.
- **Handlers are currently stubs** with TODO placeholders. This spec covers the service method; wiring handlers is out of scope.

## Files to Modify

### 1. `app/Services/Foodics/OrderService.php`

Add `getOrder(string $orderId): array` method:

```php
public function getOrder(string $orderId): array
{
    $response = $this->client->get("/orders/{$orderId}", [
        'include' => 'payments,charges,customer',
    ]);

    return $response->json('order');
}
```

- Accepts the Foodics order `id` (UUID string)
- Calls `GET /orders/{id}` with `include=payments,charges,customer` (same includables as `fetchPage`)
- Returns the order array directly (same structure as each order in the list response)
- The `FoodicsApiClient` handles 401 token refresh automatically

## Data Flow

```
Webhook handler receives $webhookLog (has order_id + business_reference)
  → Resolve user: User::where('foodics_ref', $webhookLog->business_reference)->first()
  → Set context: \Context::add('user', $user)
  → Resolve OrderService from container
  → OrderService::getOrder($webhookLog->order_id)
    → FoodicsApiClient: GET /orders/{id}?include=payments,charges,customer
    → Returns full order array
  → SyncOrder::handle($order) (called by handler, out of scope)
```

## Edge Cases

- **Order not found:** If the Foodics API returns 404, the method should throw an exception. The webhook job will retry up to 3 times with backoff (30s, 60s, 120s) as configured in `ProcessWebhookLogJob`.
- **User not found for business_reference:** The `FoodicsWebhook` middleware already rejects webhooks with unknown business references. If a user is somehow missing at the handler level, the exception will trigger a retry.

## Tasks

- [x] Add `getOrder(string $orderId): array` method to `app/Services/Foodics/OrderService.php`
- [x] Write unit test for `getOrder()` with mocked API response
- [x] Write unit test for `getOrder()` when API returns 404
