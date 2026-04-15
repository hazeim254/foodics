# 012 - SyncOrder Uses Included Product and Payment Method Data

## Overview

Update the sync workflow to use product and payment method details already embedded in Foodics order payloads when calling:

- `GET /v5/orders?include=products,payments.payment_method,charges,customer`

This removes unnecessary enrichment calls for product details during normal order synchronization.

## Context

- The order list stub at `json-stubs/foodics/list-orders.json` shows that:
  - `products[].product` contains full product fields (`id`, `sku`, `name`, `description`, price/cost metadata, and more).
  - `payments[].payment_method` contains full payment method metadata.
- `SyncOrder` currently performs product enrichment through `Foodics\ProductService` for each line item.
- `OrderService` currently includes `payments,charges,customer,products`; this spec aligns includes with nested relation intent for payment method.

## Goal

When syncing an order, `SyncOrder` should:

1. Use `products[].product` as the canonical source for product identity and metadata.
2. Keep transactional invoice fields from the line item itself (`quantity`, `unit_price`, `discount_amount`, `discount_type`, `taxes`).
3. Use `payments[].payment_method` directly for payment method resolution.
4. Avoid additional Foodics product endpoint calls in the standard `orders` sync path.

---

## Files to Modify

### 1. `app/Services/Foodics/OrderService.php`

Update include query parameters to explicitly request nested payment method data while preserving existing order sync data requirements.

#### Required include set

- `products`
- `payments.payment_method`
- `charges`
- `customer`

Apply this to:

- `fetchPage()`
- `getOrder()`

### 2. `app/Services/SyncOrder.php`

Refactor invoice-item preparation to consume embedded product payload (`products[].product`) instead of relying on per-line Foodics product fetch.

#### a) Product source of truth in `getInvoiceItems()`

For each order line:

1. Read embedded product object:
  - primary source: `$orderProduct['product']`
2. Resolve canonical product fields from embedded object:
  - `id`, `name`, `sku`, `description`, `barcode`, `price`, `cost`, `is_active`
3. Merge with transactional line fields:
  - `quantity`, `unit_price`, `discount_amount`, `discount_type`, `taxes`
4. Pass enriched payload to `Daftra\ProductService::getProductByFoodicsData(...)`.
5. Build invoice item `item` from canonical product name (fallback to `Foodics Product`).

#### b) Dependency and caching impact

- Remove normal-path reliance on `Foodics\ProductService::getProduct(...)` for order list sync.
- Remove product API cache map that only existed to de-duplicate remote product fetches.
- Keep sync deterministic: no silent skip when product identity is missing.

#### c) Fallback behavior

If embedded `product` object is missing/partial:

- product ID fallback sequence:
  1. `$orderProduct['product']['id']`
  2. `$orderProduct['id']` (if integration still supplies line-level product ID)
- if no valid product ID can be resolved: throw a clear runtime exception.
- name fallback: `Foodics Product`
- description fallback: empty string
- sku fallback: product ID

### 3. `app/Services/Daftra/ProductService.php`

No new endpoint behavior is required in this spec; service should continue receiving enriched product arrays from `SyncOrder`.

Ensure mapping expectations remain consistent with canonical embedded product fields:

- `name` -> `Product.name`
- `description` -> `Product.description`
- `sku` (or id fallback) -> `Product.product_code`
- `barcode` -> `Product.barcode`
- `price` -> `Product.unit_price`
- `cost` -> `Product.buy_price` (when present)
- `is_active` -> `Product.status` per existing integration behavior

---

## Data Flow (After Change)

```text
OrderService::fetchNewOrders()
  -> GET /v5/orders?include=products,payments.payment_method,charges,customer
  -> SyncOrder::handle($order)
      -> getInvoiceItems($order['products'])
          -> read canonical metadata from products[].product
          -> merge transactional values from line item
          -> Daftra\ProductService::getProductByFoodicsData(enrichedProduct)
          -> build InvoiceItem
      -> resolve payment methods from payments[].payment_method
      -> create invoice + payments on Daftra
```

---

## Testing Requirements

### 1. Update SyncOrder tests

Files (as needed):

- `tests/Feature/Services/SyncOrderTest.php`
- `tests/Feature/Services/SyncOrderTaxTest.php`

Add/adjust tests to assert:

- invoice item name is derived from `products[].product.name`
- enriched product passed to Daftra service includes embedded canonical fields (`id`, `name`, `sku`, `description`)
- transactional values still come from order line (`quantity`, `unit_price`, `discount_amount`, taxes)
- no Foodics product endpoint fetch is required for standard included-payload orders

### 2. Update OrderService tests

If OrderService tests exist, assert include parameters now request nested payment method relation:

- contains `payments.payment_method`
- still includes `products`, `charges`, and `customer`

### 3. Regression coverage for degraded payloads

Add at least one case for missing embedded `product` object to verify fallback and explicit failure behavior are correct.

---

## Edge Cases

- **Missing embedded product object:** apply fallback resolution; fail explicitly when no product ID is available.
- **Missing embedded payment method object:** fail through existing payment resolution path, do not silently skip payment creation.
- **Duplicate product lines:** process lines normally without external product fetch dedup logic.
- **Partial embedded product values:** apply standard defaults (`Foodics Product`, empty description, SKU fallback to ID).

---

## TODO

- [x] Update `OrderService` include params to include nested payment method relation.
- [x] Refactor `SyncOrder` product enrichment to use embedded `products[].product`.
- [x] Remove unnecessary per-line Foodics product endpoint dependency in standard sync path.
- [x] Update SyncOrder feature tests for include-driven enrichment behavior.
- [x] Add regression coverage for missing embedded product object handling.

---

## References

- Existing spec: `spec/011-foodics-product-service.md`
- Order payload stub: `json-stubs/foodics/list-orders.json`
- Sync workflow target: `app/Services/SyncOrder.php`
- Order fetch source: `app/Services/Foodics/OrderService.php`

