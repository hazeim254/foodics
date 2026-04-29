# 030 — Sync Foodics combo products as invoice items

## Overview

Sync products sold inside Foodics combos to Daftra as normal invoice items.

Foodics sends combo wrappers on `order.combos[]`, and each wrapper contains `products[]` entries with the same line-level fields used by normal `order.products[]` entries. Daftra does not need a separate combo entity or combo header line, so this spec only flattens combo products into the existing invoice item flow.

Depends on `spec/023-order-includes-and-status-filter.md` and `spec/024-sync-modifier-options.md`, which introduced the broad order include set and the reusable normal product / option invoice item handling.

## Context

- `json-stubs/foodics/combo-order.json` shows an order with `products: []` and `combos[0].products[]` containing "Medium Burger" and "Fries".
- Each combo product line includes transactional fields such as `quantity`, `unit_price`, `discount_amount`, `total_price`, and a nested `product` object with canonical product metadata.
- `SyncOrder::resolveDaftraInvoiceId()` currently builds invoice items from `$order['products']` only, so combo-only orders can create invoices without the purchased products.
- `BuildsInvoiceItems::getInvoiceItems()` already knows how to convert a Foodics product line into a Daftra invoice item, including product resolution, line discount, taxes, and modifier options.
- `BuildsInvoiceItems::resolveUniqueTaxes()` currently walks normal products, product options, and charges. Combo product taxes must be part of the same pre-resolution pass before invoice items are built.

## Decisions

| Concern | Decision |
|---------|----------|
| Foodics include set | Add `combos.products.product` to `OrderService::ORDER_INCLUDES` so combo child products carry the same canonical metadata as normal order products. |
| Daftra representation | Do not create or sync a Daftra combo item. Only the child products inside each combo become `InvoiceItem` rows. |
| Product handling | Reuse the existing normal product path in `BuildsInvoiceItems::getInvoiceItems()`. Combo product lines should be passed in the same shape as `order.products[]`. |
| Ordering | Emit normal `order.products[]` first, then combo products in `order.combos[]` order and each combo's `products[]` order. No separator or parent combo line is emitted. |
| Quantity and unit price | Use each combo product line's own `quantity` and `unit_price`, exactly like a normal product line. Do not multiply by the parent combo's `quantity`; Foodics already reflects the effective quantity on `combo.products[].quantity`. |
| Product identity | Use `combo.products[].product.id` as the canonical Foodics product id, falling back to the line `id` through the existing `resolveFoodicsProductId()` behavior. |
| Taxes | Resolve and emit taxes from combo product lines through `$this->taxMap`, same as normal products. |
| Modifier options on combo products | Ignore combo product modifier options for now. Do not add `combos.products.options.modifier_option` or emit option lines from combo products in this spec. |
| Combo wrapper discounts | Do not create a combo-level discount line. This spec relies on Foodics product-line `discount_amount` / `tax_exclusive_discount_amount` for amounts that need to affect Daftra invoice lines. |
| Missing `combos` key | Treat as an empty array; existing non-combo orders must behave unchanged. |
| Returned orders / credit notes | Apply the same combo product flattening to returned orders, so Daftra credit notes include returned combo child products. |

## Required include set update

Current `OrderService::ORDER_INCLUDES` already contains combo-related paths, but it does not request the nested product object for each combo product. Add:

```
combos.products.product
```

The include string should contain all existing paths plus the new path:

```
branch,charges,payments.payment_method,discount,products,products.taxes,charges.taxes,products.product,products.options,products.options.modifier_option,combos.products,combos.products.product,charges.charge,products.discount,combos.discount,combos.products.options.taxes,combos.products.taxes,products.options.taxes,customer
```

Apply this to both:

- `fetchPage()` for batch sync.
- `getOrder()` for webhook-driven single-order sync.

## Files to modify

### 1. `app/Services/Foodics/OrderService.php`

Add `combos.products.product` to `ORDER_INCLUDES`.

No status filter changes are required. Batch fetch should continue using `filter[status] = 4,5`, and `getOrder()` should continue omitting `filter[status]`.

### 2. `app/Services/Concerns/BuildsInvoiceItems.php`

Add a helper that returns every Foodics product line that should become a Daftra invoice item:

```php
protected function getOrderProductLines(array $order): array
{
    $products = $order['products'] ?? [];

    foreach ($order['combos'] ?? [] as $combo) {
        foreach ($combo['products'] ?? [] as $comboProduct) {
            unset($comboProduct['options']);

            $products[] = $comboProduct;
        }
    }

    return $products;
}
```

Use this helper anywhere the full order's product lines are needed. The `unset($comboProduct['options'])` is intentional: normal product options are still handled, but combo product options are out of scope for this spec and must not be emitted by the existing `getInvoiceItems()` option loop.

Update `resolveUniqueTaxes(array $order)` to resolve taxes from:

1. Normal `order.products[]` lines and their modifier options.
2. Combo child product lines from `order.combos[].products[]`.
3. Charges.

Do not resolve taxes from combo product modifier options in this spec.

Do not add a separate combo invoice builder. Combo products should flow into the existing `getInvoiceItems()` method unchanged.

### 3. `app/Services/SyncOrder.php`

In `resolveDaftraInvoiceId()`, replace:

```php
$invoiceItems = $this->getInvoiceItems($order['products']);
```

with:

```php
$invoiceItems = $this->getInvoiceItems($this->getOrderProductLines($order));
```

Keep charge invoice items appended after all product and combo product lines.

### 4. `app/Services/SyncCreditNote.php`

In `resolveDaftraCreditNoteId()`, replace:

```php
$invoiceItems = $this->getInvoiceItems($order['products'] ?? []);
```

with:

```php
$invoiceItems = $this->getInvoiceItems($this->getOrderProductLines($order));
```

Returned combo products should be represented on Daftra credit notes the same way completed combo products are represented on Daftra invoices.

## Tests

### 1. `tests/Feature/Services/Foodics/OrderServiceTest.php`

Update include assertions in `hasFullIncludes()` and the "requests all include paths" test to require `combos.products.product`.

Add or update a focused assertion that both `fetchNewOrders()` and `getOrder()` request `combos.products.product`.

### 2. `tests/Feature/Services/SyncOrderTest.php`

Add tests covering:

- **`it('emits combo products as normal invoice items')`** — use `json-stubs/foodics/combo-order.json`, sync the first order, and assert the created Daftra invoice payload contains invoice lines for "Medium Burger" and "Fries".
- **`it('does not emit a combo wrapper invoice item')`** — assert the invoice item count matches combo child products plus charges/options only, and does not include an item named after a combo wrapper.
- **`it('processes normal products before combo products')`** — craft an order with one normal product and one combo with two products; assert line ordering is `[normal product, combo product, combo product]` before charges.
- **`it('uses embedded combo product metadata for Daftra product resolution')`** — assert `ProductService::getProductByFoodicsData()` receives the nested `combo.products[].product` id/name/sku fields.
- **`it('throws InvalidOrderLineException when a combo product has no resolvable Foodics id')`** — remove both `combo.products[].product.id` and line-level `id`, then assert the same exception path as normal products.
- **`it('ignores modifier options on combo products')`** — craft a combo product with `options`; assert only the combo product line is emitted for that combo product.

### 3. `tests/Feature/Services/SyncCreditNoteTest.php`

Add a test that a returned order containing combo products creates a Daftra credit note payload with invoice lines for those combo child products.

### 4. `tests/Feature/Services/SyncOrderTaxTest.php`

Add a test that a tax appearing only on a combo product is resolved before invoice item creation and emitted as a Daftra integer tax id on that combo product invoice line.

## Edge cases

| Scenario | Behaviour |
|----------|-----------|
| `combos` missing or empty | Invoice items are built only from `order.products[]`, as today. |
| `products` missing or empty but combos present | Combo child products still create invoice items. |
| Combo has no `products` key | Skip that combo wrapper. |
| Combo product has no nested `product` object but has line-level `id` | Existing product fallback behavior applies. |
| Combo product has no resolvable product id | Throw `InvalidOrderLineException`, matching normal product lines. |
| Combo product has `quantity > 1` | Use the combo product line quantity directly. |
| Parent combo has `quantity > 1` | Do not multiply child line quantities by the combo quantity; `combo.products[].quantity` is already authoritative. |
| Combo product has zero `unit_price` | Emit the line, matching normal product behavior. |
| Combo product has `options` | Ignore those options for now; emit only the combo product line. |
| Combo wrapper has `discount_amount` | No standalone discount line is emitted in this spec. |

## Out of scope

- Creating or syncing a Daftra product for the combo wrapper itself.
- Creating a Daftra invoice line for the combo wrapper.
- Syncing modifier options on combo products.
- Reconciling combo-level discounts that are not distributed onto combo product lines.
- Tax inclusivity and rounding reconciliation.

## Tasks

- [ ] Add `combos.products.product` to `OrderService::ORDER_INCLUDES`.
- [ ] Add `BuildsInvoiceItems::getOrderProductLines(array $order): array`.
- [ ] Update `SyncOrder::resolveDaftraInvoiceId()` to pass flattened product lines to `getInvoiceItems()`.
- [ ] Update `SyncCreditNote::resolveDaftraCreditNoteId()` to pass flattened product lines to `getInvoiceItems()`.
- [ ] Update `BuildsInvoiceItems::resolveUniqueTaxes()` to resolve taxes from normal product lines, normal product options, combo product lines, and charges.
- [ ] Update `OrderServiceTest` include assertions.
- [ ] Add combo invoice item tests in `SyncOrderTest`.
- [ ] Add combo credit note item coverage in `SyncCreditNoteTest`.
- [ ] Add combo product tax resolution coverage in `SyncOrderTaxTest`.
- [ ] Run `php artisan test --compact tests/Feature/Services/Foodics/OrderServiceTest.php tests/Feature/Services/SyncOrderTest.php tests/Feature/Services/SyncCreditNoteTest.php tests/Feature/Services/SyncOrderTaxTest.php`.
- [ ] Run `vendor/bin/pint --dirty --format agent`.

## References

- `json-stubs/foodics/combo-order.json`
- `app/Services/Foodics/OrderService.php`
- `app/Services/SyncOrder.php`
- `app/Services/SyncCreditNote.php`
- `app/Services/Concerns/BuildsInvoiceItems.php`
- `spec/023-order-includes-and-status-filter.md`
- `spec/024-sync-modifier-options.md`
