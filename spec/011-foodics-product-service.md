# 011 - Foodics Product Service and SyncOrder Product Enrichment

## Overview

Create a new `Foodics\ProductService` responsible for fetching product details from Foodics by product ID, then update `SyncOrder` to use this service for canonical product fields (`name`, `sku`, `description`) instead of relying on product details embedded in order payloads.

## Context

- **Current state:** `SyncOrder` currently builds invoice items from order-level product payloads and passes that same payload to `Daftra\ProductService`.
- **Problem:** Order payloads can contain partial/stale product snapshots. We need a reliable source of truth for key product attributes.
- **Foodics endpoint:** `GET /products/{productId}` returns product data under the `data` key.
- **Required fields for sync:** `id`, `name`, `sku`, `description` (and existing pricing fields already used in Daftra product creation).
- **Existing architecture:** `OrderService` already exists under `app/Services/Foodics` and uses `FoodicsApiClient`; new service should follow the same pattern and DI style.

## Goal

When syncing an order:
1. Resolve each order line product ID.
2. Fetch canonical product details from Foodics via the new service.
3. Use fetched details (`name`, `sku`, `description`) when resolving/creating the Daftra product and when building invoice line `item` names.

This keeps order synchronization independent from possibly incomplete order payload product objects.

---

## Files to Create

### 1. `app/Services/Foodics/ProductService.php`

Create a dedicated Foodics product service:

- Constructor dependency:
  - `FoodicsApiClient $client`
- Public method:
  - `getProduct(string $productId): array`

### Method contract: `getProduct(string $productId): array`

Behavior:
1. Call:
   - `GET /products/{productId}`
2. Throw on non-success response (`$response->throw()`).
3. Return:
   - `$response->json('data')` (full product object).
4. If `data` is missing/null, throw a runtime exception with enough context (`productId` + response body).

Expected minimal returned shape (subset):

```php
[
    'id' => 'uuid',
    'name' => 'Beef Burger',
    'sku' => 'sk-0001',
    'description' => 'Beef Burger Sandwich',
    // ...other fields from Foodics product object
]
```

---

## Files to Modify

### 2. `app/Services/SyncOrder.php`

Inject `App\Services\Foodics\ProductService` into the constructor and use it during invoice-item preparation.

#### a) Product enrichment in `getInvoiceItems()`

For each order product line:
1. Extract Foodics product ID from line payload:
   - primary: `$orderProduct['id']`
   - fallback for nested shapes: `$orderProduct['product']['id']`
2. Fetch canonical product details:
   - `$foodicsProduct = $this->foodicsProductService->getProduct($foodicsProductId);`
3. Merge line-level transactional values from order line (`quantity`, `unit_price`, `discount_amount`, `discount_type`, `taxes`) with canonical product metadata (`name`, `sku`, `description`) from Foodics product endpoint.
4. Pass the enriched payload to `Daftra\ProductService::getProductByFoodicsData(...)`.
5. Build invoice item name from enriched product `name` (fallback to `Foodics Product`).

#### b) Per-order in-memory product cache

To avoid repeated API calls when the same product appears multiple times in one order, add a temporary cache map on `SyncOrder` keyed by Foodics product ID.

- Reset cache at start of `handle()`.
- Cache only for the current order execution (no DB persistence in this spec).

---

### 3. `app/Services/Daftra/ProductService.php`

Update `ProductService` to support canonical Foodics product mapping and SKU-first lookup strategy.

#### a) Lookup strategy in `getProduct(array $foodicsProduct): ?int`

Replace single-code lookup behavior with:
1. Try Daftra lookup by Foodics `sku` first (mapped to Daftra `product_code`).
2. If not found (empty rows), retry lookup using Foodics product `id` as `product_code`.
3. If both lookups are empty, return `null`.
4. If any lookup request fails (non-success HTTP), throw runtime exception.

Expected request sequence:

```text
GET /api2/products?product_code={sku}
GET /api2/products?product_code={foodics_id}   // only when sku lookup is empty
```

If Foodics `sku` is empty/null, skip directly to Foodics `id`.

#### b) Field mapping in `buildCreatePayload(array $foodicsProduct, string $foodicsId): array`

Ensure payload explicitly maps Foodics fields into Daftra product payload:

- `foodics.name` => `Product.name`
- `foodics.description` => `Product.description`
- `foodics.sku` => `Product.product_code` (fallback: `foodics.id`)
- `foodics.barcode` => `Product.barcode`
- `foodics.price` => `Product.unit_price`
- `foodics.cost` => `Product.buy_price` (omit when null)
- `foodics.is_active` => `Product.status` (`true => 0`, `false => 1`)

Keep existing defaults/fallbacks:
- missing `name` => `Foodics Product`
- missing `description` => empty string
- missing `price` => `0`
- missing `sku` => use `foodics.id`

#### c) Approved field mapping (clear and direct only)

Only include fields with explicit, reliable mapping for the current integration:

| Foodics field | Daftra field | Notes |
| --- | --- | --- |
| `sku` | `Product.product_code` | Primary lookup/create key |
| `id` | `Product.product_code` (fallback) | Used only when `sku` is empty |
| `name` | `Product.name` | Required by Daftra create |
| `description` | `Product.description` | Optional text |
| `barcode` | `Product.barcode` | Direct mapping |
| `price` | `Product.unit_price` | Required by Daftra create |
| `cost` | `Product.buy_price` | Optional; omit when null |
| `is_active` | `Product.status` | Keep current integration behavior (`true => 0`, `false => 1`) |

Notes:
- Daftra docs show both `/api2/products` and `/api2/products.json`; the code should continue using the project’s existing endpoint convention.

---

## Data Flow (After Change)

```text
SyncOrder::handle($order)
  → getInvoiceItems($order['products'])
    → for each order line:
      → productId from order line
      → Foodics\ProductService::getProduct(productId)   // canonical product fields
      → merge with order line quantity/price/taxes
      → Daftra\ProductService::getProductByFoodicsData(enrichedProduct)
        → local mapping check
        → Daftra lookup by sku
        → Daftra lookup by foodics id (fallback)
        → create if still not found (with explicit field mapping)
      → build InvoiceItem (item name from canonical product name)
  → continue invoice + payment sync as today
```

---

## Testing Requirements

### 1. Create feature tests for `Foodics\ProductService`

**File:** `tests/Feature/Services/Foodics/ProductServiceTest.php`

Add tests for:
- successful fetch returns `data` object
- 404/not-found throws `RequestException`
- successful response without `data` throws runtime exception

### 2. Update `SyncOrder` tests

**Files (as needed):**
- `tests/Feature/Services/SyncOrderTest.php`
- `tests/Feature/Services/SyncOrderTaxTest.php`

Adjust tests to assert that:
- `SyncOrder` fetches product details from `Foodics\ProductService`
- product `name` in invoice payload comes from fetched product object, not order snapshot
- Daftra product resolution receives enriched product array containing canonical `name`, `sku`, `description`
- repeated product IDs in same order use cache (single call to Foodics product endpoint per product ID)

### 3. Add/update `Daftra\ProductService` tests

**File:** `tests/Feature/Services/Daftra/ProductServiceTest.php`

Add tests for:
- lookup hits by SKU without fallback call
- lookup misses by SKU then succeeds by Foodics ID fallback
- create payload contains mapped fields:
  - `product_code` from `sku` (or `id` fallback)
  - `barcode` from Foodics barcode
  - `name`, `description`, `unit_price`, `buy_price`, `status` mappings

---

## Edge Cases

- **Order line missing product ID:** throw a clear exception and skip nothing silently.
- **Foodics product API failure for one line:** exception should bubble so sync fails explicitly (current retry/error handling path remains in place).
- **Empty/missing sku or description from Foodics:** keep existing Daftra product fallbacks (`sku` fallback to ID, `description` fallback to empty string).

---

## Tasks

- [x] Create `app/Services/Foodics/ProductService.php`
- [x] Inject new service in `app/Services/SyncOrder.php`
- [x] Enrich order product lines using canonical Foodics product details
- [x] Add per-order product cache in `SyncOrder`
- [x] Update `app/Services/Daftra/ProductService.php` lookup to `sku` then `id` fallback
- [x] Update `app/Services/Daftra/ProductService.php` payload mapping from Foodics fields
- [x] Add `tests/Feature/Services/Foodics/ProductServiceTest.php`
- [x] Update `SyncOrder` feature tests for product enrichment and caching behavior
- [x] Add/update `tests/Feature/Services/Daftra/ProductServiceTest.php`
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run targeted tests:
  - `php artisan test --compact tests/Feature/Services/Foodics/ProductServiceTest.php`
  - `php artisan test --compact tests/Feature/Services/Daftra/ProductServiceTest.php`
  - `php artisan test --compact tests/Feature/Services/SyncOrderTest.php`
  - `php artisan test --compact tests/Feature/Services/SyncOrderTaxTest.php`

---

## References

- Foodics API docs - Products: [https://apidocs.foodics.com/core/resources/products.html#the-product-object](https://apidocs.foodics.com/core/resources/products.html#the-product-object)
- Foodics API docs - Get Product: `GET /products/{productId}`
- Existing related service: `app/Services/Foodics/OrderService.php`
- Target consumer: `app/Services/SyncOrder.php`
