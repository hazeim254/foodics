# 026 — Returned orders synced as Daftra credit notes

## Overview

Extend the sync pipeline to handle Foodics returned orders (`status=5`) by creating a **Daftra credit note** linked back to the original invoice via `subscription_id`. Today the `filter[status]=4` restriction in `OrderService::fetchPage()` explicitly excludes returns; this spec lifts that restriction and introduces a second sync path that emits credit notes instead of invoices.

Depends on `spec/023` (includes + status filter), `spec/024` (modifier option lines), and `spec/025` (tax match) being merged — the credit-note line-item builder reuses the same product / option / tax pipeline those specs established.

## Context

- Per the Foodics Accounting/ERP guide's "Fetching Sales" section, completed sales are `status=4` and returned orders are `status=5`. Returned orders "must be deducted from completed orders". In Daftra's model, the correct representation of a return is a **credit note** that points at the original invoice via `subscription_id`. The user has confirmed: `subscription_id` on the credit note must equal the Daftra invoice id of the original order.
- A Foodics return order is a standalone order entity. It carries its own `id` and `reference`, and a top-level `original_order` object that identifies the completed order it reverses (see `json-stubs/foodics/get-order.json:51` where the field exists but is null for this completed-order fixture). When `status=5`, `original_order.id` is the Foodics UUID of the original completed order. `original_order.reference` is its reference number.
- Foodics guarantees `return.reference > original.reference` (a return is created after its original), so under `sort=reference&filter[status]=4,5` the original is always returned before its return on the same page — or on an earlier page, since pagination uses `reference_after` ascending.
- The current `OrderService::fetchNewOrders()` cursor advances via `Invoice::where('status', Synced)->max('foodics_reference')`. Today, only completed orders become Invoice rows, so the cursor only advances past completed orders. After this spec, returns also become rows (of a new `type`) and the cursor will correctly advance past both kinds — a return at reference `00300` marks "we've handled everything ≤ 00300".
- `SyncOrder::handle()` today is single-purpose: completed order → Daftra invoice. This spec introduces branching: `order.status === 5` dispatches to a credit-note path; anything else stays on the existing invoice path.
- Daftra credit-note API surface (per https://docs.daftara.dev/15115249e0): only `PUT /credit_notes/{id}` is publicly documented. The **create** endpoint is not in the public docs, but the conventional Daftra pattern (mirroring `POST /api2/invoices`) is `POST /api2/credit_notes`. This is a spec risk: flagged below under Decisions → Create endpoint.
- Daftra credit-note payload shape (from the edit endpoint docs): `InvoiceBase` + `InvoiceItem[]`. Required: `store_id`, `client_id`. Key optional fields: `subscription_id` (nullable — "invoice id which this invoice follows … indicates which invoice is being refunded"), `no`, `date`, `notes`, `discount_amount`. Read-only: `type` (value `5` = credit note), `summary_*`. Sub-objects: `InvoiceItem[]` with the same shape as on invoices (`product_id`, `unit_price`, `quantity`, `tax1`, `tax2`, `discount`, `discount_type`, `item`).
- Unlike `POST /api2/invoices`, the current Daftra invoice creation path does **not** send `store_id`. Daftra appears to infer it (likely from the authenticated site). Credit notes may behave the same way; we'll omit `store_id` and only add it if the API rejects the payload. Verified via out-of-band test call before coding if feasible; otherwise treat as a known risk and iterate.
- `InvoiceSyncStatus` (`Pending` / `Synced` / `Failed`) is reusable for credit-note rows as-is — the state machine is the same (local row created pending, flipped to synced on success, failed on throw).

## Decisions

| Concern | Decision |
|---------|----------|
| Return representation locally | A **second `invoices` row** with a new `type` column valued `credit_note` (existing rows become `type = invoice`). Not a nested field on the original row: (a) the duplicate-guard and retry lifecycle in `SyncOrder` already operate on single rows keyed by `foodics_id`, and a return has its own `foodics_id`; (b) Foodics may emit multiple partial returns against the same original, so one-to-many is the correct shape; (c) the UI can render them as separate rows without special cases. |
| Linking original ↔ credit note | Nullable `original_invoice_id` self-FK on `invoices` (`invoices.id`, not `foodics_id` — resilient to Foodics id changes or deletes). Populated on credit-note rows. Null on invoice rows. |
| Fetching returns | Change `OrderService::fetchPage()` filter from `filter[status]=4` to `filter[status]=4,5`. Single fetch, same cursor, same pagination. `getOrder()` still omits the filter (single-id lookups are not filtered). |
| Cursor safety under mixed statuses | No change needed. `fetchNewOrders()` already advances `reference_after` strictly by the last row on the previous page, and only considers `Synced` invoices when picking the starting cursor. After this spec, both `type=invoice` and `type=credit_note` rows count toward `max(foodics_reference)` (a single `status=Synced` filter continues to cover both, since rows of either type reach `Synced` the same way). This is intentional — if we ignored credit notes, we'd re-fetch them forever. |
| Dispatch in `SyncOrder` | `SyncOrder::handle()` branches on `(int) ($order['status'] ?? 0) === 5` early. Status-5 goes to a new `syncReturn(array $order)` method; everything else runs the existing invoice path unchanged. No enum for status values — it's a Foodics integer and adding an enum is scope creep. |
| Require original synced | A return cannot be emitted without a Daftra `subscription_id`, and that means the original's local row must exist with a non-null `daftra_id` and `status = Synced`. If the original isn't synced yet, **throw** a new `OriginalInvoiceNotSyncedException` — the job retry mechanism re-runs the return after the original catches up. Do not mark the credit-note row as `Failed` in this case — leave as `Pending` so operators can see it's waiting. Actually: follow the existing pattern — `runSync()` flips to `Failed` on throw; we live with that and rely on retry to flip back to `Pending` on next attempt. Don't invent new lifecycle states for this edge case. |
| Original never present | If `original_order` is missing entirely from the return payload, throw `InvalidOrderLineException('Return order is missing original_order reference.')`. Same as how malformed orders are handled elsewhere. Don't silently skip: silent drops cause accounting drift. |
| Credit-note line items | Reuse `SyncOrder::getInvoiceItems()` + `addChargeInvoiceItems()` **as-is** on the return payload. The return carries the same `products[]` / `charges[]` shape as the original order, so modifier-option lines, charge lines, product lines, tax resolution, discount handling all reuse the spec/024 pipeline. **Amounts stay positive** on the payload — Daftra's `type=5` (credit note) flips the sign internally. Do not manually negate unit_price or quantity. |
| Partial returns | Handled naturally. A partial return has fewer products / smaller quantities on its own order payload; we emit exactly those lines. No reconciliation against the original's lines is needed — Daftra treats each credit note independently and reports net totals. |
| Multiple returns per original | Each return produces its own credit-note row, each pointing at the same `original_invoice_id`. No uniqueness constraint on `original_invoice_id`. |
| Client on the credit note | Re-resolve from `return.customer` if present, else `original_invoice.client_id` (read from the original local row via `daftra_metadata` or re-fetch from Daftra), else the user's default client (same fallback as invoices). Reading from the original avoids a second client create/match round-trip. |
| Discount / notes / date | `date = return.business_date`, `discount_amount = return.discount_amount ?? 0`, `notes = return.kitchen_notes`. Mirrors the invoice path exactly. |
| `no` (credit-note number) | Do **not** send `no` — let Daftra auto-generate. Matches current invoice behaviour. |
| `po_number` | Send `return.id` (the return's Foodics UUID), same convention as the invoice path. Keeps the existing "Foodics ID" custom-field lookup working for credit notes too. |
| Idempotency guard | Reuse `skipIfAlreadySynced()` as-is. It keys on `foodics_id` / `foodics_reference` — the return has its own distinct values, so the guard does the right thing without modification. |
| Payments on credit notes | **Out of scope.** Foodics returns can carry negative payments but Daftra's credit-note payment model isn't documented in the linked reference. Defer to a future spec and log a warning if the return has non-empty `payments[]`. |
| Daftra create endpoint | Assume `POST /api2/credit_notes` based on the symmetric-to-invoices convention. If the integration surfaces a 404 / different path, the failure is loud (exception surfaces through sync), and we adjust. Document the assumption on `InvoiceService::createCreditNote()` and in the spec. |
| Lookup-by-foodics-id for credit notes | Add `InvoiceService::getCreditNote(string $foodicsId): ?array` that calls `/api2/credit_notes` with the `Foodics ID` custom-field filter (same pattern as `getInvoice()`). Used by `resolveDaftraCreditNoteId()` to avoid duplicate create on retry. |
| `type` column default | `'invoice'`. Backfill existing rows to `'invoice'` in the migration. |
| New custom exception | `App\Exceptions\OriginalInvoiceNotSyncedException extends RuntimeException implements LoggableException` — same pattern as `InvoiceAlreadyExistsException` / `InvalidOrderLineException`. Carries `originalFoodicsId` and `returnFoodicsId` via fluent setters. |

## Files to create

### `app/Exceptions/OriginalInvoiceNotSyncedException.php`

New custom exception for the case where a return order is processed before its original is fully synced.

- Namespace: `App\Exceptions`.
- Extends `\RuntimeException`, implements `LoggableException`.
- `report()` → `Log::warning(message, ['exception' => self::class, 'original_foodics_id' => ..., 'return_foodics_id' => ...])`.
- Fluent setters: `setOriginalFoodicsId(?string)`, `setReturnFoodicsId(?string)`. Match the ergonomics of `InvalidOrderLineException`.
- Default message: `'Original invoice not yet synced; cannot emit credit note.'`.

### `app/Services/Daftra/CreditNoteService.php` (optional — see below)

If `InvoiceService` grows past ~200 lines after adding credit-note methods, extract a `CreditNoteService` mirroring its shape (`getCreditNote`, `createCreditNote`, `getCreditNoteById`, `updateCreditNote`, `deleteCreditNote`). Otherwise keep the methods on `InvoiceService` — credit notes share most of the invoice schema and extracting prematurely duplicates the `DaftraApiClient` wiring. **Default: keep it on `InvoiceService`** and revisit only if the file becomes unwieldy.

### `database/migrations/2026_04_xx_xxxxxx_add_type_and_original_invoice_id_to_invoices_table.php`

Migration that:

1. Adds `type` (string, not null, default `'invoice'`, after `foodics_reference`) to `invoices`.
2. Adds `original_invoice_id` (nullable unsignedBigInteger, after `type`) to `invoices`.
3. Foreign key: `original_invoice_id` → `invoices.id` ON DELETE SET NULL.
4. Index on `type`.
5. Composite index on `(user_id, type, status)` — the invoices list UI will likely want to filter by type and status together; pre-empt that.
6. Backfill: default handles existing rows (`'invoice'`) — no explicit update needed.

**Rollback (`down`)**: drop foreign key, drop columns. Drop indexes first.

### `database/factories/InvoiceFactory.php` — add a `creditNote` state

Existing factory gets a `creditNote(Invoice $original)` state that sets `type = 'credit_note'`, `original_invoice_id = $original->id`, and a distinct `foodics_id` / `foodics_reference`. Keep the default state unchanged (`type = 'invoice'`).

## Files to modify

### 1. `app/Models/Invoice.php`

- Add `type` and `original_invoice_id` to `$fillable`.
- Add an `InvoiceType` enum (see below) and cast `type` to it in `casts()`.
- Add relationships:
  ```php
  public function originalInvoice(): BelongsTo
  {
      return $this->belongsTo(Invoice::class, 'original_invoice_id');
  }

  public function creditNotes(): HasMany
  {
      return $this->hasMany(Invoice::class, 'original_invoice_id');
  }
  ```

### 2. `app/Enums/InvoiceType.php` (create alongside)

```php
enum InvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
```

Mirrors `InvoiceSyncStatus` shape, including a `values()` static and, if the UI needs a label, a `badgeClasses()` method (skip for now; add when the invoices page renders the distinction).

### 3. `app/Services/Foodics/OrderService.php`

- Change `fetchPage()`:
  ```php
  'filter[status]' => '4,5',
  ```
- `getOrder()` is unchanged — still no `filter[status]`.
- `ORDER_INCLUDES` is unchanged — returns share the same shape as completed orders.

### 4. `app/Services/SyncOrder.php`

#### a) Branch in `handle()`

```php
public function handle(array $order): void
{
    $this->currentOrderId = $order['id'];

    try {
        $this->skipIfAlreadySynced($order['id'], $order['reference']);
    } catch (InvoiceAlreadyExistsException $e) {
        return;
    }

    if ((int) ($order['status'] ?? 0) === 5) {
        $this->syncReturn($order);
        return;
    }

    // existing invoice path unchanged
    $invoice = $this->createPendingInvoice($order);
    try {
        $this->runSync($order, $invoice);
    } catch (Throwable $e) {
        $invoice->update(['status' => InvoiceSyncStatus::Failed]);
        throw $e;
    } finally {
        $this->currentOrderId = null;
    }
}
```

#### b) New `syncReturn(array $order): void`

Pipeline:

1. Resolve `$originalFoodicsId = data_get($order, 'original_order.id')`. If missing/empty → throw `InvalidOrderLineException('Return order is missing original_order reference.')`.
2. Look up the local original row:
   ```php
   $original = Invoice::query()
       ->where('user_id', Context::get('user')?->id)
       ->where('foodics_id', $originalFoodicsId)
       ->where('type', InvoiceType::Invoice)
       ->first();
   ```
3. If null, or `status !== Synced`, or `daftra_id === null` → throw `(new OriginalInvoiceNotSyncedException)->setOriginalFoodicsId($originalFoodicsId)->setReturnFoodicsId($order['id'])`. Let the job retry — `SyncInvoicesJob` / `RetryInvoiceSyncJob` rerunning after a delay will find the original synced.
4. `$creditNoteRow = $this->createPendingCreditNote($order, $original)` — analog of `createPendingInvoice` but sets `type = InvoiceType::CreditNote` and `original_invoice_id = $original->id`.
5. Run the sync body in try/catch:
   - Reset `$this->taxMap`, call `resolveUniqueTaxes($order)` — returns carry their own `products[].taxes`, `products.options.taxes`, `charges.taxes` just like orders.
   - `$daftraCreditNoteId = $this->resolveDaftraCreditNoteId($order, $creditNoteRow, $original)`.
   - Persist `daftra_id` and `daftra_metadata` the same way the invoice path does.
   - If `$order['payments']` is non-empty, `Log::warning('Return order carries payments; credit-note payments are not yet synced.', ['order_id' => $order['id'], 'payments_count' => count($order['payments'])])`.
   - Flip `$creditNoteRow->status = Synced`.
   - On throw: flip to `Failed`, rethrow.
   - `finally { $this->currentOrderId = null; }`.

#### c) New `resolveDaftraCreditNoteId(array $order, Invoice $row, Invoice $original): int`

Analog of `resolveDaftraInvoiceId`:

1. If `$row->daftra_id !== null` → return it (retry reuse).
2. Look up on Daftra by Foodics custom-field: `$existing = $this->invoiceService->getCreditNote($order['id'])`. If present → return its id.
3. Build line items via the **existing** helpers:
   ```php
   $invoiceItems = $this->getInvoiceItems($order['products'] ?? []);
   $invoiceItems = $this->addChargeInvoiceItems($invoiceItems, $order['charges'] ?? []);
   ```
4. Resolve client: `return.customer` → `$this->clientService->getClientUsingFoodicsData(...)`; fall back to `$original->daftra_metadata['client_id'] ?? null`; fall back to `$this->resolveDefaultClientId()`. (Requires that the invoice path start persisting `client_id` into `daftra_metadata` — see point e.)
5. Build the credit-note payload:
   ```php
   $payload = [
       'Invoice' => [
           'po_number' => $order['id'],
           'client_id' => $clientId,
           'subscription_id' => (int) $original->daftra_id,
           'date' => $order['business_date'],
           'discount_amount' => $order['discount_amount'] ?? 0,
           'notes' => $order['kitchen_notes'] ?? null,
       ],
       'InvoiceItem' => $invoiceItems,
   ];
   ```
   Note the same top-level `Invoice` key — Daftra reuses the `InvoiceBase` schema for credit notes.
6. `return $this->invoiceService->createCreditNote($payload);`.

#### d) New `createPendingCreditNote(array $order, Invoice $original): Invoice`

Clone of `createPendingInvoice` but with `type = CreditNote` and `original_invoice_id = $original->id`. Revives a `Failed` row for the same `foodics_id` the same way the invoice path does.

#### e) Persist `client_id` into `daftra_metadata` on the invoice path

In `runSync()`, after fetching `$daftraInvoice`, include `client_id` in the saved metadata:

```php
$invoice->update([
    'daftra_metadata' => [
        'no' => $daftraInvoice['no'] ?? null,
        'client_id' => $daftraInvoice['client_id'] ?? null,
    ],
]);
```

This is the only change to the existing invoice path and is needed so credit-note emission can resolve the client without a second Daftra lookup. Low-risk: `daftra_metadata` is a free-form JSON column.

#### f) `skipIfAlreadySynced()`

No change. Keyed on `foodics_id` / `foodics_reference`; returns have distinct values from their originals.

### 5. `app/Services/Daftra/InvoiceService.php`

Add three methods mirroring the invoice ones:

```php
public function getCreditNote(string $foodicsId): ?array
{
    $response = $this->daftraClient->get('/api2/credit_notes', [
        'custom_field' => $foodicsId,
        'custom_field_label' => 'Foodics ID',
    ]);

    if (! $response->successful()) {
        throw new \RuntimeException(
            'Daftra credit note list request failed: HTTP '.$response->status().' '.$response->body()
        );
    }

    $rows = $response->json('data') ?? [];
    if ($rows === []) {
        return null;
    }

    return $rows[0]['Invoice'] ?? null;
}

public function createCreditNote(array $data): int
{
    $response = $this->daftraClient->post('/api2/credit_notes', $data);

    if ($response->failed()) {
        throw new DaftraCreditNoteCreationFailedException(
            message: 'Daftra credit note creation failed: HTTP '.$response->status(),
            responseBody: $response->body(),
        );
    }

    $newId = $response->json('id');
    if ($newId === null || $newId === '') {
        throw new DaftraCreditNoteCreationFailedException(
            message: 'Daftra credit note creation response missing id.',
            responseBody: $response->body(),
        );
    }

    return (int) $newId;
}

public function getCreditNoteById(int $id): ?array
{
    $response = $this->daftraClient->get("/api2/credit_notes/$id");

    if (! $response->successful()) {
        return null;
    }

    return $response->json('data.Invoice') ?? null;
}
```

Add `App\Exceptions\DaftraCreditNoteCreationFailedException` alongside `DaftraInvoiceCreationFailedException` — same shape and parent.

### 6. `app/Webhooks/Handlers/OrderCreatedHandler.php`

No change. `SyncOrder::handle()` branches on `status` internally; the handler remains dispatch-agnostic. (Foodics emits a `order.created` webhook when a return is created too; the existing handler fetches the full order and the new branch takes over.)

### 7. UI — `app/Http/Controllers/InvoiceController.php` / views

Out of scope. Invoices list will show credit-note rows mixed in with default rendering (badge still shows sync status). Distinguishing the two visually is a separate spec when needed.

## Tests

### 1. `tests/Feature/Services/Foodics/OrderServiceTest.php`

- Update every `filter[status]` assertion from `'4'` to `'4,5'`.
- Keep the `it('restricts fetch to completed orders (status 4)')` name but rename to `it('restricts fetch to completed and returned orders (status 4,5)')` and update the expected value.
- Keep the assertion that `getOrder()` does not send `filter[status]`.

### 2. `tests/Feature/Services/SyncOrderReturnTest.php` (new)

Focused on the `status=5` branch. Covers:

- **`it('creates a credit note row linked to the original invoice')`** — seed a `Synced` Invoice for the original; feed `SyncOrder::handle()` a return payload pointing at it; assert a new row exists with `type = CreditNote`, `original_invoice_id = $original->id`, `foodics_id = $return['id']`, `status = Synced`, `daftra_id` populated.
- **`it('posts the credit note to Daftra with subscription_id equal to the original daftra_id')`** — mock `InvoiceService::createCreditNote`; assert the payload's `Invoice.subscription_id` equals the original's `daftra_id`.
- **`it('throws OriginalInvoiceNotSyncedException when the original is still pending')`** — seed a `Pending` original; assert the exception bubbles and the credit-note row is marked `Failed` (by `runSync`'s catch).
- **`it('throws OriginalInvoiceNotSyncedException when the original row is absent')`** — no seed; assert the exception.
- **`it('throws OriginalInvoiceNotSyncedException when the original has no daftra_id')`** — original is `Synced` but `daftra_id` is null (shouldn't happen in practice but guard anyway).
- **`it('throws InvalidOrderLineException when original_order is missing from the return payload')`** — return payload with `original_order = null`.
- **`it('reuses existing Daftra credit note id on retry')`** — seed a `Failed` credit-note row with `daftra_id = 777`; assert no `createCreditNote` call, `status` flipped to `Synced`, `daftra_id` stays `777`.
- **`it('finds an existing Daftra credit note by Foodics custom field before creating')`** — row has no `daftra_id`; mock `getCreditNote()` to return `['id' => 888]`; assert no create call; row ends up with `daftra_id = 888`.
- **`it('emits all product, option, and charge lines on the credit note')`** — assert `InvoiceItem` array on the payload contains the product line + its option lines + charge lines in the same order the invoice path would emit.
- **`it('uses the returns customer for client_id when present')`**.
- **`it('falls back to the original invoices daftra client_id when the return has no customer')`** — seed original with `daftra_metadata = ['client_id' => 42]`; assert payload uses `client_id = 42`.
- **`it('falls back to the default client when neither the return nor the original has a client')`**.
- **`it('sends discount_amount and notes from the return payload')`**.
- **`it('does not sync payments on returns and logs a warning when payments are present')`** — `Log::shouldReceive('warning')`; feed a return with one payment; assert no call to `InvoiceService::createPayment` and the warning was emitted with the order id.
- **`it('does not double-emit on duplicate webhook for the same return')`** — run `handle()` twice with the same return payload; assert the credit note is created only once and `skipIfAlreadySynced` handles the second call.
- **`it('revives a failed credit-note row instead of creating a second one')`** — seed a `Failed` credit-note row with the same `foodics_id`; run `handle()`; assert no duplicate row is created.

### 3. `tests/Feature/Services/SyncOrderTest.php`

- **`it('routes status-4 orders to the invoice path and status-5 orders to the credit-note path')`** — one test that calls `handle()` twice with the same shape differing only by `status`; assert the invoice path runs for `status=4` and the credit-note path runs for `status=5`. Use `Mockery::spy` on `InvoiceService`.
- Regression: existing invoice-path tests must still pass unmodified. The only change on the invoice path is that `daftra_metadata` now also contains `client_id` — update any test that asserts the exact shape of `daftra_metadata` to include `'client_id'`.

### 4. `tests/Feature/InvoiceFactoryTest.php` (or wherever factory coverage lives)

- Smoke test for the `creditNote()` state: asserts `type = 'credit_note'`, `original_invoice_id` set.

### 5. `tests/Feature/Services/Daftra/InvoiceServiceTest.php` (extend)

- **`it('lists credit notes by Foodics custom field')`** — verifies the `getCreditNote` HTTP contract (path, query params, `Invoice` key unwrap).
- **`it('creates a credit note and returns the new Daftra id')`** — `POST /api2/credit_notes`, response carries `id`.
- **`it('throws DaftraCreditNoteCreationFailedException on non-2xx create response')`**.
- **`it('throws DaftraCreditNoteCreationFailedException when create response has no id')`**.

### 6. Migration test

A feature test that runs the migration up and down and asserts the columns + indexes + FK exist after `up()` and are gone after `down()`. Use the existing migration-test harness if one exists; otherwise a single `RefreshDatabase` test with `Schema::hasColumn` assertions.

## Edge cases

| Scenario | Behaviour |
|----------|-----------|
| Return references an original that is itself a credit note | Shouldn't happen per Foodics model, but guard: require `type = invoice` on the original lookup. If the only match is a credit note, treat as "not synced" and throw `OriginalInvoiceNotSyncedException`. |
| Return's `reference` is somehow less than or equal to the original's | Cursor invariant violated. We still process whichever comes first in the batch; if the return arrives before the original, the exception triggers retry until the original is also synced. |
| Partial return (fewer lines than the original) | Natural. Emit exactly those lines. |
| Second return for the same original | Another credit-note row with the same `original_invoice_id`. Daftra accepts multiple credit notes against one invoice. |
| Return total exceeds original total (anomaly) | We don't check. Daftra may or may not accept it; if it rejects, the exception surfaces and the row is `Failed`. Manual intervention. |
| Return has `products = []` (head-only return) | Empty `InvoiceItem` array. Daftra may reject — log the failure and let it surface. |
| Return arrives via `order.created` webhook vs batch fetch | Both paths call `SyncOrder::handle()`. No per-path branching needed. |
| Return's tax set differs from original's (rate change between order and return) | `resolveUniqueTaxes()` runs on the return payload so all its taxes are resolved to Daftra ids. spec/025's name+value match ensures we hit or create the correct Daftra tax. |

## Out of scope

- **Credit-note payments.** Foodics returns can carry negative payments; Daftra's credit-note payment model isn't documented. Deferred. Log a warning for visibility.
- **Reflected amount / in-place invoice adjustment.** Some ERPs prefer amending the original invoice instead of issuing a credit note. Not supported by Foodics's return model and not how Daftra structures refunds.
- **UI changes.** The invoices list will render credit-note rows with default styling. A follow-up spec can add a type badge and filter.
- **`order.updated` webhook handling.** If Foodics emits an `order.updated` event on a completed order with a later return, we ignore it (existing placeholder handler). The return itself arrives as a separate `order.created` event with `status=5`.
- **Backfill of returns from before this spec lands.** Any status-5 orders that were silently skipped by the prior `filter[status]=4` filter will not be fetched automatically — their references are below the cursor. A separate one-off command can rewind the cursor; out of scope here.
- **Data fix for `EntityMapping` rows miscached before spec/025.** Already out of scope of that spec; still out of scope.
- **Webhook signature verification.** Unchanged from spec/022.

## Open questions

These are flagged for clarification before or during implementation:

1. **Daftra credit-note create endpoint path.** Assumed `POST /api2/credit_notes` by symmetry with `POST /api2/invoices`. Verify with a sandbox call or by asking the Daftra contact. If the real path differs, `InvoiceService::createCreditNote()` is the only code change.
2. **`store_id` requirement.** The public schema marks it required, but current invoice creation omits it successfully. Start by omitting. If creation 400s, thread the user's default store id through.
3. **`included` (inclusive tax) behaviour on credit notes.** Same question raised for invoices in the deferred tax-mode spec. Default to the same stance: let Daftra compute from the lines we send, don't set `included` explicitly.
4. **How Daftra exposes the `po_number` / custom-field lookup for credit notes.** Assumed parallel to invoices (`?custom_field=...&custom_field_label=Foodics ID`). Confirm during implementation; if the filter names differ, `getCreditNote()` adapts.

## Tasks

- [x] Create `app/Enums/InvoiceType.php`.
- [x] Create `app/Exceptions/OriginalInvoiceNotSyncedException.php`.
- [x] Create `app/Exceptions/DaftraCreditNoteCreationFailedException.php`.
- [x] Create migration `add_type_and_original_invoice_id_to_invoices_table`.
- [x] Update `app/Models/Invoice.php` — `$fillable`, `casts()`, relationships.
- [x] Update `database/factories/InvoiceFactory.php` — `creditNote()` state.
- [x] Update `app/Services/Foodics/OrderService.php` — `filter[status]=4,5`.
- [x] Add `getCreditNote()`, `createCreditNote()`, `getCreditNoteById()` to `app/Services/Daftra/InvoiceService.php`.
- [x] Update `app/Services/SyncOrder.php` — branch in `handle()`, add `syncReturn()`, `resolveDaftraCreditNoteId()`, `createPendingCreditNote()`, persist `client_id` into `daftra_metadata` on the invoice path.
- [x] Update `tests/Feature/Services/Foodics/OrderServiceTest.php` — status filter `4,5`.
- [x] Create `tests/Feature/Services/SyncOrderReturnTest.php` with the listed cases.
- [x] Update `tests/Feature/Services/SyncOrderTest.php` for the status-branch routing test and the `daftra_metadata` shape change.
- [x] Extend `tests/Feature/Services/Daftra/InvoiceServiceTest.php` with credit-note method coverage.
- [x] Run `php artisan test --compact tests/Feature/Services/SyncOrderReturnTest.php tests/Feature/Services/SyncOrderTest.php tests/Feature/Services/Foodics/OrderServiceTest.php tests/Feature/Services/Daftra/InvoiceServiceTest.php`.
- [x] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Resolve open questions 1–4 with a sandbox call before merging.

## References

- Foodics Accounting/ERP guide — Fetching Sales: https://developers.foodics.com/guides/Accounting/Accounting-ERP-Integration.html#fetching-sales
- Daftra credit note edit endpoint (schema source): https://docs.daftara.dev/15115249e0
- `app/Services/SyncOrder.php`
- `app/Services/Foodics/OrderService.php`
- `app/Services/Daftra/InvoiceService.php`
- `spec/023-order-includes-and-status-filter.md` — prerequisite, defines the include set and the status filter lifted here.
- `spec/024-sync-modifier-options.md` — defines the `getInvoiceItems()` pipeline reused on the credit-note path.
- `spec/025-tax-match-by-name-and-value.md` — ensures the tax resolver correctly handles returns with differing rates.
- `spec/017-sync-order-invoice-lifecycle.md` — lifecycle (pending/failed/synced) is reused unchanged.
- `spec/022-webhook-order-created.md` — webhook path that also triggers this branch via `SyncOrder::handle()`.
