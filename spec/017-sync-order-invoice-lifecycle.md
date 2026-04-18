# 017 — Sync order invoice lifecycle and sync status

## Overview

Align `App\Services\SyncOrder` with a clear local invoice lifecycle on the `invoices` table: block duplicate work when an order is already in progress or completed, insert a **pending** row when sync starts (nullable Daftra id), then set **synced** when the full sync succeeds or **failed** when it does not.

## Requirements

### 1. Invoice sync status enum

Add `App\Enums\InvoiceSyncStatus` (string-backed):

| Case    | Value    |
|---------|----------|
| Pending | `pending` |
| Failed  | `failed`  |
| Synced  | `synced`  |

Use this enum for the `invoices.status` column (Eloquent cast on `Invoice`).

### 2. Before sync — duplicate guard

Before any sync work runs, ensure **no** `invoices` row exists for the **current user** where:

- `(foodics_id` matches the order id **or** `foodics_reference` matches the order reference`) **and**
- `status` is `pending` **or** `synced`.

If such a row exists, skip the sync the same way as today (e.g. catch `InvoiceAlreadyExistsException` and return early).

Failed rows do **not** block a new sync attempt.

### 3. When sync starts — pending row

After the duplicate guard and the existing “already on Daftra” check pass, **insert** one `invoices` row with:

- `foodics_id` — Foodics order id  
- `foodics_reference` — Foodics order reference  
- `daftra_id` — `null`  
- `status` — `pending`  
- `user_id` — from request context  

`daftra_id` must be nullable at the database level for this row to be valid before Daftra returns an id.

### 4. When sync finishes — terminal status

- After **invoice creation on Daftra and payment sync** both succeed, update that row: set `daftra_id` and `status` = `synced`.
- If anything in the sync pipeline fails **after** the pending row was created (including Daftra invoice creation or payment posting), update the row’s `status` to `failed`, then rethrow so callers still see the error.

### 5. Related behaviour

- **`App\Services\Foodics\OrderService`**: when computing `max('foodics_reference')` for `reference_after`, only consider invoices whose status is **`synced`**, so in-flight or failed rows do not move the Foodics pagination cursor incorrectly.
- **`App\Services\Daftra\InvoiceService::saveMapping`**: remove or replace; mapping updates belong with the sync lifecycle in `SyncOrder` (pending → synced / failed).

### 6. Idempotent sync (invoice vs payments)

The sync pipeline must be **safe to retry**: a second run for the same Foodics order must not create duplicate Daftra invoices or duplicate Daftra payments when the first run partially succeeded.

**Resolve Daftra invoice first**

1. If the local `invoices` row already has a `daftra_id`, treat that as the target Daftra invoice for this order (do not create another invoice on Daftra).
2. Otherwise, if Daftra already has an invoice for this Foodics order (same lookup as today, e.g. custom field / `po_number` / existing `getInvoice` behaviour), obtain its Daftra id and **align local state** (create or update the local row with that `daftra_id`) instead of creating a new invoice.
3. Only call Daftra **create invoice** when there is no existing invoice for this order on Daftra and no usable `daftra_id` locally.

**Payments after invoice exists**

4. After the target `daftra_id` is known, sync **only** Foodics payments that are not already represented on Daftra (or not yet recorded as synced locally—implementation may use a stored list of stable Foodics payment keys on `invoices`, query Daftra for existing `invoice_payments`, or both; the spec requires **no duplicate payment rows** for the same Foodics payment on retry).
5. Example called out explicitly: if invoice creation succeeds but posting **invoice payments** fails (or only some payments succeed), the next sync must **not** create a new invoice; it should **resume** by posting the missing payment(s) only.

**Daftra API — list existing payments for an invoice**

Use Daftra’s **GET All Invoice Payments** endpoint to discover payments already recorded against a Daftra invoice (for deduplication and resume). Official reference: [GET All Invoice Payments](https://docs.daftara.dev/15115306e0).

- Base path matches the existing API v2 client pattern (same host/prefix as `POST /api2/invoice_payments` used today): **`GET /api2/invoice_payments`** (with the usual `.json` format suffix if your client requires it, consistent with other Daftra resources).
- **Filter by invoice:** pass **`filter[invoice_id]`** in the query string with the Daftra invoice id (the same numeric id stored in `invoices.daftra_id` / returned from invoice create). Example shape: `?filter[invoice_id]=<daftra_invoice_id>` (URL-encoded as usual when issued via HTTP client).
- Responses return **`data`** as an array of objects wrapping **`InvoicePayment`** (fields include `id`, `invoice_id`, `payment_method`, `amount`, `date`, etc. per the OpenAPI in the doc). Use this list to decide which Foodics payments still need to be posted.
- The API supports **`limit`** (default 20, max 1000) and **`page`** pagination; if an invoice could exceed one page of payments, implementation must page until all rows are read before comparing to the Foodics order.

**Terminal status vs partial progress**

6. Align `InvoiceSyncStatus` / local row updates with idempotency: avoid marking the order **fully** `synced` until both invoice creation (or adoption of an existing Daftra invoice) **and** all intended payment posts for the current Foodics order have succeeded—or define an explicit intermediate state if you prefer not to overload `synced` (document the chosen rule in code comments or this spec).

**Failure behaviour**

7. `InvoiceService::createPayment` (or equivalent) must surface API failures with a normal exception path (no `dd` / process exit) so retries and status updates behave predictably.

## Files touched (implementation)

- `app/Enums/InvoiceSyncStatus.php` (new)  
- `database/migrations/…_make_daftra_id_nullable_on_invoices_table.php` (new)  
- `app/Models/Invoice.php` — cast `status`  
- `app/Services/SyncOrder.php` — guard, pending insert, try/finally or try/catch, final update  
- `app/Services/Daftra/InvoiceService.php` — drop `saveMapping` usage from sync path; add **`listInvoicePayments` (or equivalent)** using `GET /api2/invoice_payments` with `filter[invoice_id]` per [Daftra docs](https://docs.daftara.dev/15115306e0)  
- `app/Services/Foodics/OrderService.php` — scoped `max` on synced only  
- Tests: `SyncOrder*`, `OrderServiceTest`, `InvoiceServiceTest`, factories as needed  
- Optional migration / model fields if tracking per-payment sync keys or similar (only if chosen in implementation).

## Acceptance checks

- [x] Enum exists and is used on `Invoice` for `status`.
- [x] No second sync for same `foodics_id` or same `foodics_reference` while another row is `pending` or `synced` for that user.
- [x] A pending row exists with `daftra_id` null before Daftra create is called.
- [x] Successful end-to-end sync leaves one row with `synced` and non-null `daftra_id`.
- [x] Daftra invoice creation failure leaves `failed` and does not leave `pending`.
- [x] `OrderService` cursor ignores non-synced invoices.
- [x] Re-running sync for an order whose Daftra invoice already exists does **not** call Daftra invoice create again.
- [x] If the invoice exists on Daftra but payments were never synced, a later run posts the Foodics payments; if Daftra already has ≥1 payment the payment step is skipped entirely (no Foodics-side correlation, per implementation decision).
- [x] Payment API failures are reported via exceptions (no `dd` / hard exit).

## Implementation notes

- **Payment dedup rule:** `SyncOrder::syncPaymentsIfMissing()` calls `InvoiceService::listInvoicePayments()` and posts the Foodics payments only when Daftra returns zero existing payments for the invoice. If any payment is present, payment sync is skipped. No Foodics-side payment matching is performed (spec Q1).
- **Terminal status:** the enum stays three-valued. The local row remains `pending` through both invoice creation and payment posting; it is updated to `synced` only after both succeed (spec Q2).
- **`po_number` vs custom field** lookup mismatch in `InvoiceService::getInvoice` is intentionally left out of scope.
- **Idempotent invoice resolution:** `SyncOrder::resolveDaftraInvoiceId()` prefers the local `daftra_id`, then an existing Daftra invoice (adopted onto the pending row), and only creates a new invoice as a last resort.
- **`saveMapping`** was removed from `InvoiceService`; the lifecycle (insert pending → set `daftra_id` → mark `synced`/`failed`) lives entirely in `SyncOrder`.
