# 018 — Store Foodics and Daftra metadata on invoices

## Overview

Add two nullable JSON columns (`foodics_metadata`, `daftra_metadata`) to the `invoices` table so that `SyncOrder` can persist useful display data from each side of the sync. The metadata is **not used for searching** — it is only read when rendering the invoices table on `invoices.blade.php`.

## Metadata fields

### Foodics metadata (`foodics_metadata`)

Stored as a JSON object. Extracted from the Foodics order payload during sync:

| Key | Source in order | Example |
|-----|----------------|---------|
| `total_price` | `order.total_price` | `24.15` |

Only `total_price` for now. The column is JSON so additional keys can be appended later without a migration.

### Daftra metadata (`daftra_metadata`)

Stored as a JSON object. Populated **after** the Daftra invoice is created (or found) by calling `InvoiceService::getInvoice()` with the Foodics order id:

| Key | Source in Daftra response | Example |
|-----|--------------------------|---------|
| `no` | `Invoice.no` | `"0700000AAAAA0001"` |

Only `no` (invoice number) for now. The JSON column allows future additions without a migration.

## Requirements

### 1. Migration

Add two nullable JSON columns to `invoices`:

```php
$table->json('foodics_metadata')->nullable();
$table->json('daftra_metadata')->nullable();
```

### 2. Invoice model

- Add `foodics_metadata` and `daftra_metadata` to `$fillable`.
- Add `'foodics_metadata' => 'array'` and `'daftra_metadata' => 'array'` to the `casts()` method.

### 3. SyncOrder — store Foodics metadata

In `createPendingInvoice()`, extract and persist `foodics_metadata` when creating or reviving the local invoice row:

```php
'foodics_metadata' => [
    'total_price' => (float) ($order['total_price'] ?? 0),
],
```

### 4. SyncOrder — store Daftra metadata

After the Daftra invoice id is resolved (in `runSync()`), fetch the full Daftra invoice and persist `daftra_metadata`:

```php
$daftraInvoice = $this->invoiceService->getInvoice($order['id']);

if ($daftraInvoice !== null) {
    $invoice->update([
        'daftra_metadata' => [
            'no' => $daftraInvoice['no'] ?? null,
        ],
    ]);
}
```

This uses a **separate GET call** (the existing `getInvoice()` method) rather than modifying `createInvoice`'s return value.

### 5. Invoices page — update table columns

Update `resources/views/invoices.blade.php` to show human-readable identifiers instead of raw IDs, with external links that open in a new tab:

| Column | Before | After |
|--------|--------|-------|
| Foodics Ref | `foodics_reference` (plain text) | `<a>` linking to Foodics console order page |
| Daftra ID | `$invoice->daftra_id` (raw integer, plain text) | `<a>` linking to Daftra invoice page, showing invoice number |
| Total | _(not present)_ | `$invoice->foodics_metadata['total_price']` (new column) |
| Status | _(unchanged)_ | _(unchanged)_ |
| Created | _(unchanged)_ | _(unchanged)_ |

#### Foodics order link

The Foodics Ref cell becomes a clickable link:

```
href: {FOODICS_BASE_URL}/orders/{foodics_id}
```

- `{FOODICS_BASE_URL}` — from `config('services.foodics.base_url')` (e.g. `https://console-sandbox.foodics.com`).
- `{foodics_id}` — `$invoice->foodics_id`.
- Display text: `$invoice->foodics_reference`.
- `target="_blank"`.

```blade
<a href="{{ config('services.foodics.base_url') }}/orders/{{ $invoice->foodics_id }}" target="_blank">
    {{ $invoice->foodics_reference }}
</a>
```

#### Daftra invoice link

The "Daftra ID" column header becomes "Daftra Invoice". The cell becomes a clickable link:

```
href: https://{subdomain}/owner/invoices/view/{daftra_id}
```

- `{subdomain}` — from `$invoice->user->daftra_meta['subdomain']` (already contains the full domain, e.g. `myshop.daftra.com`). Eager-load the user relation in the query.
- `{daftra_id}` — `$invoice->daftra_id` (the numeric Daftra invoice id).
- Display text: `$invoice->daftra_metadata['no']` with fallback to `$invoice->daftra_id`.
- `target="_blank"`.

```blade
@php
    $daftraNo = $invoice->daftra_metadata['no'] ?? $invoice->daftra_id;
    $daftraSubdomain = $invoice->user->daftra_meta['subdomain'] ?? null;
@endphp

@if($daftraSubdomain && $invoice->daftra_id)
    <a href="https://{{ $daftraSubdomain }}/owner/invoices/view/{{ $invoice->daftra_id }}" target="_blank">
        {{ $daftraNo }}
    </a>
@else
    {{ $daftraNo ?? '—' }}
@endif
```

The "Total" column is inserted between "Daftra Invoice" and "Status" and formats the price to 2 decimal places. Since Foodics doesn't provide a currency code in the order itself, display the raw number.

### 6. Backwards compatibility

Existing invoice rows will have `null` for both metadata columns. The view must handle `null` gracefully:

- `$invoice->foodics_metadata['total_price'] ?? '—'`
- `$invoice->daftra_metadata['no'] ?? $invoice->daftra_id ?? '—'`
- If `daftra_meta['subdomain']` is missing (e.g. user record predates that field), show plain text instead of a link.

## Files to create

### 1. `database/migrations/xxxx_add_metadata_to_invoices_table.php`

Adds `foodics_metadata` (nullable JSON) and `daftra_metadata` (nullable JSON) to the `invoices` table.

## Files to modify

### 2. `app/Models/Invoice.php`

- Add `foodics_metadata` and `daftra_metadata` to `$fillable`.
- Add array casts for both columns in `casts()`.

### 3. `app/Services/SyncOrder.php`

- `createPendingInvoice()`: pass `foodics_metadata` when creating or reviving the invoice row.
- `runSync()`: after `resolveDaftraInvoiceId()`, call `InvoiceService::getInvoice()` to fetch the Daftra invoice data and store `daftra_metadata` on the local invoice.

### 4. `resources/views/invoices.blade.php`

- Rename "Daftra ID" header to "Daftra Invoice", display `daftra_metadata['no']` with fallback to `daftra_id`.
- Make "Foodics Ref" a clickable link to `{FOODICS_BASE_URL}/orders/{foodics_id}`, `target="_blank"`.
- Make "Daftra Invoice" a clickable link to `https://{subdomain}.daftra.com/owner/invoices/view/{daftra_id}`, `target="_blank"`, with graceful fallback to plain text when subdomain is unavailable.
- Add "Total" column between "Daftra Invoice" and "Status", displaying `foodics_metadata['total_price']` with fallback.

### 5. `app/Http/Controllers/InvoiceController.php`

Eager-load the `user` relation on the invoices query so the view can access `daftra_meta['subdomain']` without N+1:

```php
$invoices = auth()->user()->invoices()
    ->with('user')
    ->orderByDesc('created_at')
    ->paginate(50);
```

## Tasks

- [x] Create migration to add `foodics_metadata` and `daftra_metadata` JSON columns
- [x] Update `Invoice` model (`$fillable` and `casts()`)
- [x] Update `SyncOrder::createPendingInvoice()` to store `foodics_metadata`
- [x] Update `SyncOrder::runSync()` to fetch and store `daftra_metadata`
- [x] Update `invoices.blade.php` table columns (Foodics ref as link, Daftra invoice number as link, Total column)
- [x] Update `InvoiceController@index` to eager-load `user` relation for subdomain access
- [x] Write/update tests for `SyncOrder` metadata storage
- [x] Write/update browser or feature test for invoices page display
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run `php artisan test --compact`

## Out of scope

- No searching or filtering on metadata fields.
- No invoice detail page (metadata is displayed inline in the table).
- No additional Foodics or Daftra metadata fields beyond `total_price` and `no` (can be added later by appending to the JSON).
