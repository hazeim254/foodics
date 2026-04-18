# 019 — Retry Sync for Pending & Failed Invoices

## Overview

Add a per-row "Retry Sync" button on the invoices table for invoices with `pending` or `failed` status. Clicking the button re-syncs that single invoice by re-fetching the order from Foodics and running it through the existing `SyncOrder` pipeline. This replaces the current UX limitation where users can only trigger a bulk sync for all orders.

## Context

- The `invoices` table stores rows with statuses: `pending`, `failed`, `synced` (via `InvoiceSyncStatus` enum).
- `SyncOrder::handle(array $order)` processes a single order through the full pipeline (duplicate guard → pending row → Daftra invoice → payments → synced).
- `SyncOrder::skipIfAlreadySynced` blocks re-sync when a `pending` or `synced` row exists. For the retry flow, the caller must reset the local row to `failed`-compatible state before calling `handle`, or `handle` must accept an option to bypass the duplicate guard when retrying.
- `Foodics/OrderService::getOrder(string $orderId)` fetches a single order by ID from the Foodics API and returns the raw order array.
- The bulk sync flow uses `SyncInvoicesJob` (queued, `ShouldBeUnique`). The retry flow dispatches a separate, single-order job.

## Requirements

### 1. Route

```
POST /invoices/{invoice}/retry-sync → InvoiceController@retrySync → named('invoices.retry-sync')
```

- Must be within the `auth` middleware group.
- Uses route-model binding (`Invoice $invoice`).
- Must verify the invoice belongs to the authenticated user (policy or explicit check).

### 2. Controller Logic — `InvoiceController::retrySync`

1. **Authorize:** abort 403 if `$invoice->user_id !== auth()->id()`.
2. **Guard status:** only `pending` and `failed` invoices are retryable. If the invoice is `synced`, redirect back with an error flash: "This invoice is already synced."
3. **Reset status:** set the invoice status to `failed` before dispatching. This is necessary because `SyncOrder::skipIfAlreadySynced` blocks on `pending` rows. By setting to `failed`, the duplicate guard lets the sync through, and `SyncOrder::createPendingInvoice` will flip the existing row back to `pending` (reusing the same row).
4. **Dispatch job:** dispatch `RetryInvoiceSyncJob` for the invoice.
5. **Redirect** back to the invoices list with flash: "Retrying sync for {foodics_reference}…"

### 3. Job — `RetryInvoiceSyncJob`

```php
class RetryInvoiceSyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public Invoice $invoice) {}

    public function handle(): void
    {
        $user = $this->invoice->user;
        Context::add('user', $user);

        if (! $user->getFoodicsToken()) {
            Log::warning("RetryInvoiceSyncJob: User #{$user->id} has no Foodics token.");
            $this->invoice->update(['status' => InvoiceSyncStatus::Failed]);

            return;
        }

        $order = app(OrderService::class)->getOrder($this->invoice->foodics_id);

        app(SyncOrder::class)->handle($order);
    }
}
```

Notes:
- Does **not** implement `ShouldBeUnique` (unlike `SyncInvoicesJob`). Multiple individual retries can run concurrently for different invoices.
- Uses `$tries = 3` so transient API failures get automatic retries at the queue level.
- On permanent failure, the `SyncOrder::handle` catch block already sets status to `failed`.
- The `finally` block in `SyncOrder::handle` does not clear a bulk-sync cache key (that is the bulk job's responsibility), so no cache cleanup is needed here.

### 4. Blade View Changes — `resources/views/invoices.blade.php`

Add a new **Actions** column at the end of the table, after the "Created" column.

#### Table header

Add after the "Created" `<th>`:

```blade
<th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Actions</th>
```

#### Table body — per row

For each invoice row, add an actions cell after the "Created" `<td>`:

```blade
<td class="px-6 py-4">
    @if(in_array($invoice->status, [App\Enums\InvoiceSyncStatus::Pending, App\Enums\InvoiceSyncStatus::Failed]))
        <form method="POST" action="{{ route('invoices.retry-sync', $invoice) }}" class="inline">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Retry Sync
            </button>
        </form>
    @endif
</td>
```

Design notes:
- Button styling matches the existing "Sync Now" button (Daftra blue `#4A90D9`).
- Only shown for `pending` and `failed` rows; `synced` rows show nothing in the actions column.
- Uses inline `<form>` with `@csrf` for POST (same pattern as the bulk Sync Now button).

### 5. Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Invoice belongs to another user | 403 Forbidden |
| Invoice status is `synced` | Redirect back with error flash "This invoice is already synced." |
| User has no Foodics token | Job logs warning, sets invoice to `failed` |
| Foodics API returns an error for the order | `SyncOrder` sets status to `failed`, job may retry up to `$tries` |
| Order already has a Daftra invoice (partial sync) | `SyncOrder::resolveDaftraInvoiceId` reuses the existing `daftra_id` on the row, skips invoice creation, resumes payment sync |
| Bulk sync running simultaneously for same user | `SyncOrder::skipIfAlreadySynced` will block if the row becomes `pending`/`synced` during bulk sync; the retry job will then set `failed` and the duplicate guard will pass or not depending on timing — the row-level state machine handles this correctly |
| Retry clicked multiple times rapidly | Multiple `RetryInvoiceSyncJob` instances may be dispatched. `SyncOrder` is idempotent by design (spec 017), so concurrent runs for the same invoice are safe. Consider adding `ShouldBeUnique` keyed on invoice ID if deduplication is desired (out of scope for initial implementation). |

## Files to Create

### 1. `app/Jobs/RetryInvoiceSyncJob.php`

Queued job that fetches the Foodics order by `$invoice->foodics_id` and runs it through `SyncOrder::handle`. Sets user context, checks Foodics token, and delegates entirely to the existing sync pipeline.

### 2. `tests/Feature/RetryInvoiceSyncTest.php`

Feature tests covering:

- Guest cannot access the retry route (redirect to login).
- User cannot retry another user's invoice (403).
- User cannot retry a synced invoice (redirect with error flash).
- Pending invoice: retry dispatches job, job runs, invoice becomes synced.
- Failed invoice: retry dispatches job, job runs, invoice becomes synced.
- Failed invoice where Daftra invoice already exists: retry reuses `daftra_id`, does not create duplicate.
- User with no Foodics token: job sets invoice to `failed`.
- The "Retry Sync" button is visible on pending/failed rows and not on synced rows in the blade view.

## Files to Modify

### 3. `routes/web.php`

Add inside the `auth` middleware group:

```php
Route::post('/invoices/{invoice}/retry-sync', [InvoiceController::class, 'retrySync'])->name('invoices.retry-sync');
```

### 4. `app/Http/Controllers/InvoiceController.php`

Add `retrySync` method:

```php
public function retrySync(Invoice $invoice)
{
    if ($invoice->user_id !== auth()->id()) {
        abort(403);
    }

    if ($invoice->status === InvoiceSyncStatus::Synced) {
        return redirect()->route('invoices')
            ->with('status', 'This invoice is already synced.');
    }

    $invoice->update(['status' => InvoiceSyncStatus::Failed]);

    RetryInvoiceSyncJob::dispatch($invoice);

    return redirect()->route('invoices')
        ->with('status', "Retrying sync for {$invoice->foodics_reference}…");
}
```

### 5. `resources/views/invoices.blade.php`

- Add "Actions" column header after "Created".
- Add actions `<td>` cell after the "Created" cell in each row, containing the retry button (conditionally rendered for `pending`/`failed` only).

## Tasks

- [x] Add `POST /invoices/{invoice}/retry-sync` route to `routes/web.php`
- [x] Create `app/Jobs/RetryInvoiceSyncJob.php`
- [x] Add `retrySync` method to `app/Http/Controllers/InvoiceController.php`
- [x] Update `resources/views/invoices.blade.php` — add Actions column with Retry Sync button
- [x] Write feature tests in `tests/Feature/RetryInvoiceSyncTest.php`
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run tests to verify everything passes

## Out of Scope

- No bulk retry (select multiple and retry).
- No confirmation modal before retrying.
- No real-time polling or status updates for individual retries (user refreshes to see updated status).
- No `ShouldBeUnique` on the retry job (to keep initial implementation simple).
- No rate limiting on the retry endpoint.
