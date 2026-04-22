# 024 — Sync modifier options as invoice line items

## Overview

Emit Foodics `products[].options[]` (modifier options) as their own Daftra invoice line items, so modifier revenue is recorded on the invoice instead of silently dropped.

Depends on `spec/023-order-includes-and-status-filter.md` being merged first (it bundles the `products.options` and `products.options.taxes` paths that surface this data).

**Combos are explicitly out of scope** for this spec — `order.combos[]` and any `combos.*.options[]` are not handled here and will be covered by a later spec.

## Context

- In `app/Services/SyncOrder.php::getInvoiceItems()`, each `$orderProduct` becomes a single Daftra `InvoiceItem`. The `$orderProduct['options']` array is currently ignored.
- A modifier option in Foodics (e.g. "Cheese Slice", "Extra Shot") has its own `quantity`, `unit_price`, `total_price`, `taxes`, and a nested `modifier_option` sub-object with `id`, `name`, `sku`, `price`, `cost`, `is_active` — effectively a "mini product" with the same shape `Daftra\ProductService::getProductByFoodicsData()` already accepts.
- `SyncOrder::resolveUniqueTaxes()` already walks `product['options'][].taxes`, so option taxes are already resolved into `$this->taxMap`. No change needed there.
- The guide (Fetching Sales) lists `products.options.taxes` as a tax contribution path but does not list option prices as separate subtotal contributors — Daftra will compute invoice totals from the lines we send, and the guide's `subtotal_price`, `total_price` are used for reference, not posted directly as an order-level figure on a single Daftra line. Emitting options as their own lines is the correct representation for a line-item-based ERP.

## Decisions

| Concern | Decision |
|---------|----------|
| Line granularity | One Daftra invoice line per `products[].options[]` entry. |
| Ordering | Option lines are emitted **immediately after** their parent product's line, preserving human-readable grouping on the invoice. |
| Product resolution | Reuse `Daftra\ProductService::getProductByFoodicsData()` with the modifier's canonical fields. Modifier options map to their own Daftra products (distinct `product_code`). |
| Include set | Add `products.options.modifier_option` to `OrderService::ORDER_INCLUDES`. Without it, option payloads lack `name`/`sku`/`id` and can't be mapped. |
| Quantity | `option.quantity` (never assume 1). |
| Unit price | `option.unit_price`. Inclusive/exclusive tax reconciliation (`tax_exclusive_unit_price`) is intentionally deferred to a later spec covering Foodics tax mode. |
| Discount | `option.discount_amount` if present, else `option.tax_exclusive_discount_amount`, else `0`. `discount_type = 2` to match the existing product-line convention. |
| Taxes on the line | `tax1` and `tax2` in Daftra are **integer Daftra tax IDs**, not Foodics tax ids and not inline tax objects. Every Foodics tax id on the line must be translated through `$this->taxMap` (which `resolveUniqueTaxes()` populated before `getInvoiceItems()` runs — see ordering note below) into its Daftra id before use. Take the first two resolved Daftra ids for `tax1` / `tax2`. |
| &gt;2 unique taxes on one option | Daftra only supports two slots per line. Take the first two resolved Daftra ids in the order they appear on `option.taxes`; drop the rest and log a `Log::warning` with `order_id`, `option_id`, and the dropped Foodics tax ids. This matches the decision in `spec/002-handle-taxes.md` for products. |
| Unresolved tax ids | If a Foodics tax id on the option is not present in `$this->taxMap` (should not happen because `resolveUniqueTaxes()` walks option taxes), skip that id — don't send a null or a raw Foodics id as `tax1` / `tax2`. Log a warning. |
| Item display name | `option.modifier_option.name` with fallback `Modifier Option`. |
| Zero-price / free options | Still emit the line (zero unit_price). Free modifiers are useful to show on the invoice; Daftra accepts zero-price items. |
| Missing `modifier_option` sub-object | Defensive fallback: use `option.id` as SKU, name = `Modifier Option`, log a warning. Do **not** skip the line (would drop revenue for non-free options). |
| Missing option `id` entirely | Throw `App\Exceptions\InvalidOrderLineException` — new custom exception (see below). Same stance as `resolveFoodicsProductId()` for products, which is also updated to throw this exception instead of the bare `\RuntimeException` it throws today. |

## Files to create

### `app/Exceptions/InvalidOrderLineException.php`

New custom exception covering both "order product line is missing a Foodics product id" and "order option line is missing a Foodics id" failures. Follows the existing exception convention in this codebase (see `app/Exceptions/InvoiceAlreadyExistsException.php`):

- Namespace: `App\Exceptions`.
- Extends `\RuntimeException`.
- Implements `App\Exceptions\LoggableException` with a `report()` method that `Log::warning`s the message + `exception` class, matching `InvoiceAlreadyExistsException`.
- Default message: `'Order line is missing a required Foodics id.'`. Callers pass a specific message.
- Constructor signature matches the sibling exceptions (`string $message`, `int $code = 0`, `?Throwable $previous = null`).

## Files to modify

### 1. `app/Services/Foodics/OrderService.php`

Extend `ORDER_INCLUDES` (introduced in `spec/023`) to include the modifier detail:

```
branch,charges,payments.payment_method,discount,products,products.taxes,charges.taxes,products.product,products.options,products.options.modifier_option,combos.products,charges.charge,products.discount,combos.discount,combos.products.options.taxes,combos.products.taxes,products.options.taxes
```

Only `products.options.modifier_option` is added. Combo-related paths remain as in `spec/023`, unused by `SyncOrder` until the combos spec lands.

### 2. `app/Services/SyncOrder.php`

#### a) New helper: `buildOptionInvoiceItem(array $option): array`

Builds a single Daftra invoice line from one modifier option:

1. Resolve the modifier Foodics id:
   - primary: `option.modifier_option.id`
   - fallback: `option.id`
   - if neither: throw `new InvalidOrderLineException('Order option line is missing a Foodics id.')`.
2. Build the enriched product payload from `option.modifier_option` (with defaults) — same field names as `getInvoiceItems()` currently uses for products (`id`, `name`, `sku`, `description`, `barcode`, `price`, `cost`, `is_active`). Keep `name` fallback = `Modifier Option`; `sku` fallback = the Foodics id.
3. Call `$this->productService->getProductByFoodicsData($enriched)` to obtain the Daftra product id.
4. Translate the option's Foodics tax ids to Daftra tax ids and pick the first two. `tax1` / `tax2` **must be the Daftra integer IDs** resolved via `$this->taxMap`, never a raw Foodics id or a null placeholder. Use the same pipeline the product line uses today:

   ```php
   $daftraTaxIds = collect($option['taxes'] ?? [])
       ->pluck('id')
       ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
       ->filter() // drop unresolved
       ->values()
       ->take(2);
   ```

   If the underlying collection (before `take(2)`) has more than 2 entries, emit a `Log::warning` with the dropped tax ids (see Decisions table).
5. Compute line discount:
   ```php
   $discount = $option['discount_amount']
       ?? $option['tax_exclusive_discount_amount']
       ?? 0;
   ```
6. Return the invoice item array:
   ```php
   [
       'product_id' => $daftraProductId,
       'item' => $enriched['name'],
       'quantity' => $option['quantity'] ?? 1,
       'unit_price' => $option['unit_price'] ?? 0,
       'discount' => $discount,
       'discount_type' => 2,
       'tax1' => $daftraTaxIds->get(0),
       'tax2' => $daftraTaxIds->get(1),
   ];
   ```

#### b) Update `getInvoiceItems(array $products): array`

After appending each product's invoice item, iterate its options and append an invoice item per option:

```php
foreach ($products as $orderProduct) {
    // ... existing product invoice item build ...
    $invoiceItems[] = [ /* product line */ ];

    foreach ($orderProduct['options'] ?? [] as $option) {
        $invoiceItems[] = $this->buildOptionInvoiceItem($option);
    }
}
```

Order within the array: `[product, option, option, product, option, ...]`.

#### c) `resolveUniqueTaxes()`

No change — it already merges `$option['taxes']` into `$allTaxes`, so by the time `getInvoiceItems()` runs every Foodics tax id appearing on any product or option line has a corresponding Daftra id in `$this->taxMap`. The option line builder is **downstream** of this and must not resolve taxes ad-hoc.

**Ordering invariant (do not change):** `runSync()` calls `resolveUniqueTaxes()` **before** `resolveDaftraInvoiceId()` / `getInvoiceItems()`. The option line builder depends on this ordering; a test asserts it below.

#### d) `resolveFoodicsProductId()` — swap bare `\RuntimeException`

Replace the current `throw new \RuntimeException('Order product line is missing a Foodics product id.');` with `throw new InvalidOrderLineException('Order product line is missing a Foodics product id.');` so both product-line and option-line failures surface through one typed exception.

### 3. `database/factories/` and fixtures

No schema or factory changes. The existing `json-stubs/foodics/get-order.json` fixture already contains modifier options with `modifier_option` sub-objects, which the updated tests can consume directly.

## Tests

### 1. `tests/Feature/Services/Foodics/OrderServiceTest.php`

Update the include-substring assertions introduced in `spec/023` to also assert `str_contains($p['include'], 'products.options.modifier_option')` on both `fetchPage` and `getOrder` matchers.

### 2. `tests/Feature/Services/SyncOrderTest.php`

Add / update:

- **`it('emits each modifier option as its own invoice line')`** — feed an order payload with one product that has two options; assert the resulting invoice item array has exactly 3 lines (1 product + 2 options) and that the options directly follow the parent product.
- **`it('uses modifier_option.name as the option line item name')`** — assert the `item` field on the option line matches `modifier_option.name`.
- **`it('propagates option quantity, unit_price, and taxes to the invoice line')`** — assert `quantity`, `unit_price` come from the option payload (not the parent product), and that `tax1` on the option line is the **Daftra integer id** returned by `TaxService::resolveTaxId()` for that Foodics tax — not the raw Foodics tax id.
- **`it('uses Daftra tax ids (not Foodics ids) for tax1 and tax2 on option lines')`** — mock `TaxService::resolveTaxId()` to return a known Daftra integer (e.g. `99`); assert `tax1 === 99`, and assert `tax1` is of type `int`, not string/UUID.
- **`it('caps option line taxes at two and warns when more are present')`** — craft an option with 3 distinct taxes; assert exactly two Daftra ids are emitted (the first two in `option.taxes` order) and that `Log::warning` is called with the dropped Foodics tax id.
- **`it('skips unresolved Foodics tax ids on option lines')`** — corrupt `$this->taxMap` so one Foodics id has no entry (e.g. via partial mock); assert the unresolved id is skipped and the next resolved id fills its slot rather than a null or a raw Foodics id being sent.
- **`it('falls back to tax_exclusive_discount_amount when option discount_amount is missing')`** — craft an option with no `discount_amount` but `tax_exclusive_discount_amount = 1.5`; assert the line's `discount` = `1.5`.
- **`it('emits zero-price options as invoice lines')`** — craft an option with `unit_price = 0`; assert a line is still emitted with `unit_price = 0`.
- **`it('falls back to a generic name when modifier_option sub-object is missing')`** — craft an option with only `id`, `quantity`, `unit_price`, `taxes`; assert `item = 'Modifier Option'`, `sku` passed to `ProductService` equals the option id.
- **`it('throws InvalidOrderLineException when an option has no resolvable id')`** — craft an option with no `id` and no `modifier_option.id`; assert `App\Exceptions\InvalidOrderLineException` bubbles out of `SyncOrder::handle()`.
- **`it('throws InvalidOrderLineException when a product line has no resolvable id')`** — regression cover for the `resolveFoodicsProductId()` swap; craft a product line with no `product.id` and no line-level `id`; assert `InvalidOrderLineException` (not bare `\RuntimeException`).

### 3. `tests/Feature/Services/SyncOrderTaxTest.php`

- Update / add: assert that option taxes are merged into `$taxMap` by `resolveUniqueTaxes()` **before** `getInvoiceItems()` runs (i.e. that a tax that appears only on an option, not on any product or charge, still ends up resolved in `$taxMap` and shows up as a Daftra id on the emitted option line).
- Assert the `tax1` / `tax2` values on **option** invoice lines are the Daftra integer ids produced by `TaxService::resolveTaxId()`, not the Foodics ids from the payload.

## Edge cases

| Scenario | Behaviour |
|----------|-----------|
| Product has no `options` key | Option loop is skipped (`?? []`), same as today. |
| Option has `taxes = []` | `tax1`/`tax2` on the line are `null`, same as any tax-free product line. |
| Same modifier option appears on multiple products in one order | Each occurrence emits its own line. `Daftra\ProductService` cache dedup handles the create/lookup. |
| Combos contain option-like structures | Ignored this spec; handled in the combos spec. |
| `modifier_option.sku` is empty | Fall back to the modifier id, matching `getInvoiceItems()`'s product-line behaviour today. |

## Out of scope

- Combos: `order.combos[]` and any combo option handling.
- Tax inclusivity / `tax_exclusive_unit_price` reconciliation (separate future spec).
- Rounding, tips (spec TBD).
- Returned-order deduction (`spec/025`).
- Order-level `discount` object vs `discount_amount` scalar reconciliation (separate future spec).

## Tasks

- [x] Create `app/Exceptions/InvalidOrderLineException.php` (extends `\RuntimeException`, implements `LoggableException`).
- [x] Add `products.options.modifier_option` to `OrderService::ORDER_INCLUDES`.
- [x] Swap the bare `\RuntimeException` in `SyncOrder::resolveFoodicsProductId()` for `InvalidOrderLineException`.
- [x] Add `SyncOrder::buildOptionInvoiceItem()`.
- [x] Update `SyncOrder::getInvoiceItems()` to append option lines after each parent product.
- [x] Update `tests/Feature/Services/Foodics/OrderServiceTest.php` substring assertions.
- [x] Add the option-related tests in `tests/Feature/Services/SyncOrderTest.php`, including the two `InvalidOrderLineException` cases.
- [x] Verify / update option-tax behaviour in `tests/Feature/Services/SyncOrderTaxTest.php`.
- [x] Run `php artisan test --compact tests/Feature/Services/SyncOrderTest.php tests/Feature/Services/SyncOrderTaxTest.php tests/Feature/Services/Foodics/OrderServiceTest.php`.
- [x] Run `vendor/bin/pint --dirty --format agent`.

## References

- Foodics Accounting guide — Fetching Sales: https://developers.foodics.com/guides/Accounting/Accounting-ERP-Integration.html#fetching-sales
- `app/Services/SyncOrder.php`
- `app/Services/Daftra/ProductService.php`
- `app/Services/Foodics/OrderService.php`
- `json-stubs/foodics/get-order.json` (modifier options with `modifier_option` sub-object)
- `spec/023-order-includes-and-status-filter.md` (prerequisite)
- `spec/012-syncorder-use-order-includes.md` (embedded-product pattern reused here)
