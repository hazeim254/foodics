# 005 - Foodics Order Service

## Overview

Create a `Foodics\OrderService` that fetches orders from the Foodics API using cursor-based pagination via `reference_after`. The service determines the starting point by querying the last synced invoice for the current user. The `invoices` table needs a `foodics_reference` column to store the Foodics order reference used for pagination.

## Context

- **Foodics List Orders endpoint:** `GET /orders` returns 50 orders per page.
- **Pagination constraint:** The `page` parameter is capped at 10 pages (max 500 orders). The API explicitly recommends using `sort=reference` + `filter[reference_after]` for cursor-based pagination instead.
- **`reference_after`** accepts the `reference` value (a string like `"00292"`) of the last processed order and returns the next batch. On first sync (no previous orders), omit the `filter[reference_after]` parameter entirely.
- **`FoodicsApiClient`** (spec 004) is already implemented and registered in the container. It uses the `__call` proxy pattern — calls like `$client->get('/orders', [...])` work directly.
- **`SyncOrder`** service already processes individual order arrays. It stores the `foodics_id` (UUID) on the `invoices` table but does **not** store the `foodics_reference`.
- **The `invoices` table** currently has: `id`, `user_id`, `foodics_id`, `daftra_id`, `status`, `timestamps`. There is no `foodics_reference` column.

## Problem: Why `foodics_reference` is Needed

The Foodics API's recommended pagination uses `filter[reference_after]`, which requires the **order reference** (e.g., `"00292"`), not the order `id` (UUID). Currently:

1. The `invoices` table only stores `foodics_id` (UUID).
2. There is no way to determine what `reference_after` value to use for the next sync without querying the Foodics API again.
3. Storing `foodics_reference` locally allows us to query `max('foodics_reference')` to find the cursor point instantly.

## Files to Create

### 1. Migration: `database/migrations/YYYY_add_foodics_reference_to_invoices_table.php`

Add a string column `foodics_reference` to the `invoices` table and index it for fast lookups:

```php
Schema::table('invoices', function (Blueprint $table) {
    $table->string('foodics_reference')->after('foodics_id');
    $table->index(['user_id', 'foodics_reference']);
});
```

### 2. `app/Services/Foodics/OrderService.php`

Service class that fetches paginated orders from Foodics using `reference_after`. Returns the raw list of order arrays — no syncing.

**Constructor:**
- Accepts `FoodicsApiClient $client` (injected via container, scoped to user)

**`fetchNewOrders(): array`** — Main entry point:
1. Resolve user from `\Context::get('user')`
2. Determine the starting point:
   - Query `Invoice::where('user_id', $user->id)->max('foodics_reference')`
   - If a reference is found, pass it as `reference_after`
   - If none found (first sync), omit `filter[reference_after]` entirely
2. Fetch pages using `fetchPage(?string $referenceAfter)` until an empty page is returned
3. Return the full list of order arrays

**`fetchPage(?string $referenceAfter = null): array`** — Single page fetch:
1. Build query params:
   ```php
   $params = [
       'sort' => 'reference',
       'include' => 'payments,charges,customer',
       'limit' => 50,
   ];

   if ($referenceAfter !== null) {
       $params['filter[reference_after]'] = $referenceAfter;
   }
   ```
2. Call `$this->client->get('/orders', $params)`
3. Parse the response: `$response->json('data')` — each entry is `{ "order": { ... } }`
4. Extract the last order's `reference` for the next cursor
5. Return `['orders' => [...], 'next_reference' => ?string]`

**Pagination loop logic:**
```php
$user = \Context::get('user');
$referenceAfter = Invoice::where('user_id', $user->id)->max('foodics_reference');
$allOrders = [];
$hasMore = true;

while ($hasMore) {
    $result = $this->fetchPage($referenceAfter);
    $orders = $result['orders'];

    if (empty($orders)) {
        $hasMore = false;
        continue;
    }

    $allOrders = array_merge($allOrders, $orders);
    $referenceAfter = $result['next_reference'];
}

return $allOrders;
```

## Files to Modify

### 3. `app/Models/Invoice.php`

Add `foodics_reference` to `$fillable`:

```php
protected $fillable = [
    'user_id',
    'foodics_id',
    'foodics_reference',
    'daftra_id',
    'status',
];
```

### 4. `database/factories/InvoiceFactory.php`

Add `foodics_reference` to the factory definition:

```php
'foodics_reference' => (string) fake()->randomNumber(5),
```

### 5. `app/Services/SyncOrder.php`

Update the mapping step (line ~80) to pass the order's `reference` to `saveMapping`:

```php
$this->invoiceService->saveMapping($order['id'], $daftraInvoiceId, $order['reference']);
```

### 6. `app/Services/Daftra/InvoiceService.php`

Update `saveMapping()` to accept and persist the `foodics_reference`:

```php
public function saveMapping(string $foodicsId, int $daftraId, string $foodicsReference): Invoice
```

The method should store all three values (`foodics_id`, `daftra_id`, `foodics_reference`) on the `Invoice` model along with the current user's ID from `\Context::get('user')`.

## Data Flow

```
OrderService::fetchNewOrders()
  → Query Invoice::max('foodics_reference') for user
  → Loop: GET /orders?sort=reference&filter[reference_after]={cursor}
    → Collect all order arrays
    → Update cursor to last order's reference
  → Return all new orders (caller decides what to do with them)

Each order → SyncOrder::handle($order) (called elsewhere)
  → Creates Daftra invoice
  → Saves Invoice with foodics_id, daftra_id, foodics_reference
  → Next sync picks up from the highest reference
```

## Edge Cases

- **First sync ever:** Omit `filter[reference_after]` entirely — no parameter needed.
- **Large order volumes:** The loop continues until an empty page is returned. Each page returns up to 50 orders, so there is no 10-page limit with this approach.
- **Concurrent syncs:** `skipIfAlreadySyncedLocally` in `SyncOrder` already handles duplicate prevention by checking `foodics_id` existence.

## Tasks

- [x] Create migration to add `foodics_reference` column to `invoices` table
- [x] Update `Invoice` model `$fillable` to include `foodics_reference`
- [x] Update `InvoiceFactory` to include `foodics_reference`
- [x] Create `app/Services/Foodics/OrderService.php`
- [x] Update `SyncOrder::handle()` to pass order `reference` to `saveMapping`
- [x] Update `InvoiceService::saveMapping()` to accept and persist `foodics_reference`
- [x] Write feature tests for `OrderService::fetchNewOrders()` (mock API responses)
- [x] Write feature tests verifying `foodics_reference` is stored correctly on sync
- [x] Update existing `SyncOrder` tests if they break due to the new parameter
