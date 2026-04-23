# 023 — Order includes and status filter

## Overview

Align `Foodics\OrderService` with the [Foodics Accounting/ERP guide — Fetching Sales](https://developers.foodics.com/guides/Accounting/Accounting-ERP-Integration.html#fetching-sales) by (1) requesting every include path the guide lists for total-price reconstruction and (2) restricting the fetch to completed orders only.

This spec is the prerequisite for upcoming specs that process modifier options, combos, per-line discounts, and returns (`spec/024`, `spec/025`). Without it, those relations are simply absent from the response payload.

## Context

- Current `OrderService::fetchPage()` and `OrderService::getOrder()` request `include=products.product,payments.payment_method,charges,customer`.
- The Accounting guide's sample request uses a much broader include set in order to expose the nested tax, discount, option, and combo amounts needed to reconcile the invoice totals.
- The guide also restricts the fetch with `filter[status]=4,5` (4 = completed, 5 = returned) and notes that returned orders must later be deducted from completed orders.
- Per project decision, **this spec only requests status `4`**. Status `5` will be added when `spec/025-returned-orders-and-refunds.md` lands, so we don't poll returns we can't yet process and don't advance the `reference_after` cursor past orders we're not handling.
- `SyncOrder` consumes the returned payload but is **not** changed in this spec — the extra includes make more data *available* without altering behaviour. `spec/024` and later specs consume the new fields.

## Decisions

| Concern | Decision |
|---------|----------|
| Include set | Match the guide's sample verbatim (see below). One string literal, used by both `fetchPage()` and `getOrder()`. |
| Status filter | `filter[status]=4` only for now. Add `5` in `spec/025`. |
| Where the include string lives | Private class constant `OrderService::ORDER_INCLUDES` to keep the two call sites in sync. |
| Applies to `getOrder()` too | Yes. The webhook handler (`OrderCreatedHandler`) uses `getOrder()`, and it must see the same shape as the batch sync. |
| Backwards compatibility | `SyncOrder` reads the current fields via `data_get` / `??`. Extra keys in the payload are ignored today, consumed by the follow-up specs. No behaviour change expected. |

## Required include set

From the guide's sample request:

```
branch
charges
payments.payment_method
discount
products
products.taxes
charges.taxes
products.product
products.options
combos.products
charges.charge
products.discount
combos.discount
combos.products.options.taxes
combos.products.taxes
products.options.taxes
```

Serialized as the `include` query parameter (comma-separated, no spaces):

```
branch,charges,payments.payment_method,discount,products,products.taxes,charges.taxes,products.product,products.options,combos.products,charges.charge,products.discount,combos.discount,combos.products.options.taxes,combos.products.taxes,products.options.taxes
```

## Files to modify

### 1. `app/Services/Foodics/OrderService.php`

- Add a private constant:

  ```php
  private const ORDER_INCLUDES = 'branch,charges,payments.payment_method,discount,products,products.taxes,charges.taxes,products.product,products.options,combos.products,charges.charge,products.discount,combos.discount,combos.products.options.taxes,combos.products.taxes,products.options.taxes';
  ```

- In `fetchPage()`, replace the hard-coded `include` string with `self::ORDER_INCLUDES` and add the status filter:

  ```php
  $params = [
      'sort' => 'reference',
      'include' => self::ORDER_INCLUDES,
      'filter[status]' => '4',
      'limit' => 50,
  ];
  ```

- In `getOrder()`, replace the hard-coded `include` string with `self::ORDER_INCLUDES`. Do **not** add a `filter[status]` here — `getOrder()` is called from `OrderCreatedHandler` in response to a specific webhook for a known order id, and we don't want to filter that single-order lookup by status.

### 2. `tests/Feature/Services/Foodics/OrderServiceTest.php`

Update every `Mockery::on(...)` param matcher that currently asserts `$p['include'] === 'products.product,payments.payment_method,charges,customer'` to assert against the new include string. Since the string is long, test assertions should read it from the service via a shared test helper or assert on a few required substrings rather than the full literal:

- `str_contains($p['include'], 'products.options.taxes')`
- `str_contains($p['include'], 'combos.products.taxes')`
- `str_contains($p['include'], 'products.discount')`
- `str_contains($p['include'], 'combos.discount')`
- `str_contains($p['include'], 'charges.charge')`
- `str_contains($p['include'], 'branch')`
- `str_contains($p['include'], 'payments.payment_method')`

For the `fetchPage()` call sites, also assert `($p['filter[status]'] ?? null) === '4'`.

For the `getOrder()` call site, assert that `filter[status]` is **not** present.

Add two new tests:

1. `it('requests all include paths recommended by the accounting guide')` — calls `fetchNewOrders()` with a single empty page and asserts the include string contains every one of the 16 paths listed above.
2. `it('restricts fetch to completed orders (status 4)')` — asserts `filter[status]=4` on the `fetchPage` call and asserts it is absent on `getOrder`.

No behaviour change tests are required for `SyncOrder` — extra payload fields are inert today.

## Out of scope

- Handling returned orders (status `5`) and their deduction — covered by `spec/025`.
- Consuming modifier options, combos, per-line discount objects, branch, or order-level discount object — covered by `spec/024` and later specs.
- Updating `json-stubs/foodics/list-orders.json` to reflect the new includes' fuller payload shape — done alongside `spec/024` when those fields are first consumed.
- Any changes to `SyncOrder`, `TaxService`, `ProductService`, `PaymentMethodService`.

## Tasks

- [ ] Add `ORDER_INCLUDES` constant and use it in `fetchPage()` and `getOrder()` in `app/Services/Foodics/OrderService.php`.
- [ ] Add `filter[status] => '4'` to `fetchPage()` only.
- [ ] Update existing tests in `tests/Feature/Services/Foodics/OrderServiceTest.php` to assert against the new include string (via substring checks) and new status filter.
- [ ] Add the two new tests listed above.
- [ ] Run `php artisan test --compact tests/Feature/Services/Foodics/OrderServiceTest.php`.
- [ ] Run `vendor/bin/pint --dirty --format agent`.

## References

- Foodics Accounting guide — Fetching Sales: https://developers.foodics.com/guides/Accounting/Accounting-ERP-Integration.html#fetching-sales
- `app/Services/Foodics/OrderService.php`
- `app/Webhooks/Handlers/OrderCreatedHandler.php` (consumer of `getOrder()`)
- `spec/005-foodics-order-service.md` (original service spec)
- `spec/012-syncorder-use-order-includes.md` (previous include tuning)
- `spec/022-webhook-order-created.md` (webhook path that calls `getOrder()`)
