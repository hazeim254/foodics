# 016 - Invoices Page with Sync Trigger

## Overview

Replace the placeholder invoices page with a functional listing that displays synced invoices in a table, and add a "Sync Now" button that triggers the sync process via a queued job. When a sync is in progress, the UI shows a "Syncing…" indicator and auto-polls for completion, then reloads the page.

## Context

- The `invoices` table stores synced invoice records with columns: `id`, `user_id`, `foodics_id`, `daftra_id`, `foodics_reference`, `status`, `created_at`, `updated_at`.
- `InvoiceController` currently returns a placeholder view with no data.
- Sync currently exists only as an artisan command (`orders:sync {user_id}`) which uses `OrderService::fetchNewOrders()` and `SyncOrder::handle()`.
- Both `FoodicsApiClient` and `DaftraApiClient` resolve the current user from `Context::get('user')`, bound in `AppServiceProvider`.
- The app uses Blade templates with Tailwind CSS v4, Alpine.js, and follows the layout from spec 015 (`layouts.app`).

## Invoices Table

Display columns in this order:

| Column | Source | Format |
|--------|--------|--------|
| Foodics Ref | `invoice.foodics_reference` | Plain text |
| Daftra ID | `invoice.daftra_id` | Plain text (integer) |
| Status | `invoice.status` | Badge-style label (e.g. "synced") |
| Created | `invoice.created_at` | Formatted date (`M d, Y` e.g. "Jan 15, 2026") |

- Rows are scoped to `auth()->user()` (only the logged-in user's invoices).
- Ordered by `created_at` descending (newest first).
- Paginated (50 per page) with custom Tailwind pagination matching the app's design language.

### Empty State

When no invoices exist, show a friendly message instead of the table:

```
No invoices yet.
Sync your Foodics orders to see them here.
```

### Sync in Progress Indicator

When a sync job is running for the user, display a "Syncing…" indicator with a spinner in place of the "Sync Now" button. Alpine.js polls `/invoices/sync-status` every 3 seconds while syncing is active. When the response indicates syncing is complete, the page reloads to show fresh data.

## Sync Process

### Routes

```
GET  /invoices             → InvoiceController@index      → named('invoices')
POST /invoices/sync       → InvoiceController@sync       → named('invoices.sync')
GET  /invoices/sync-status → InvoiceController@syncStatus → named('invoices.sync-status')
```

`GET /invoices/sync-status` returns JSON: `{"syncing": true}` or `{"syncing": false}`.

### Flow

1. User clicks "Sync Now" button on the invoices page.
2. `POST /invoices/sync` sets a cache key, dispatches `SyncInvoicesJob` for the authenticated user, redirects back with "Sync started."
3. If a sync is already in progress (cache key present), the controller redirects back with "Sync is already in progress." and does not dispatch.
4. The page loads with `$syncing = true`, Alpine.js starts polling `/invoices/sync-status`.
5. The job runs the same logic as `SyncOrdersCommand`:
   - Resolves the user, sets `Context::add('user', $user)`.
   - If the user has no Foodics token, logs a warning and returns gracefully.
   - Calls `OrderService::fetchNewOrders()`.
   - Loops through each order, calls `SyncOrder::handle($order)`.
   - Catches exceptions per-order (same as the command), logs failures.
6. When the job finishes (success or failure), the `finally` block clears the cache key.
7. On the next poll, `/invoices/sync-status` returns `{"syncing": false}`, Alpine.js reloads the page.

### Job: `App\Jobs\SyncInvoicesJob`

```php
class SyncInvoicesJob implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 300;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(): void
    {
        try {
            Context::add('user', $this->user);

            if (! $this->user->getFoodicsToken()) {
                logger()->warning("SyncInvoicesJob: User #{$this->user->id} has no Foodics token.");

                return;
            }

            $orders = app(OrderService::class)->fetchNewOrders();

            foreach ($orders as $order) {
                try {
                    app(SyncOrder::class)->handle($order);
                } catch (\Throwable $e) {
                    logger()->error("Failed to sync order: {$e->getMessage()}");
                }
            }
        } finally {
            Cache::forget('sync_in_progress:' . $this->user->id);
        }
    }
}
```

### Duplicate Prevention (Two Layers)

1. **Controller-level (UX):** `Cache::has('sync_in_progress:{user_id}')` check prevents dispatching a second job and gives the user an immediate "Sync is already in progress." flash message.
2. **Queue-level (Safety net):** `ShouldBeUnique` with `uniqueId()` prevents the same job from being queued twice even if the controller check is bypassed (race condition, double-click, etc.).

### Cache Key for UI State

- **Key:** `sync_in_progress:{user_id}`
- **Set:** In the controller when dispatching, with a 5-minute TTL (fallback in case job crashes before clearing).
- **Cleared:** In the job's `finally` block.
- **Read:** By `InvoiceController@index` (passes `$syncing` to view) and `InvoiceController@syncStatus` (returns JSON).

## Files to Create

### 1. `app/Jobs/SyncInvoicesJob.php`

Queued job that runs the sync for a given user. Implements `ShouldBeUnique` with `$uniqueFor = 300` and `uniqueId()` returning the user ID. Sets context, checks for Foodics token (returns gracefully with a warning log if missing), syncs orders, and clears the cache key in a `finally` block.

### 2. `app/Http/Controllers/InvoiceController.php` (modify)

Change from invokable to a regular controller with three methods:

```php
class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = auth()->user()->invoices()
            ->orderByDesc('created_at')
            ->paginate(50);

        $syncing = Cache::has('sync_in_progress:' . auth()->id());

        return view('invoices', compact('invoices', 'syncing'));
    }

    public function sync()
    {
        $cacheKey = 'sync_in_progress:' . auth()->id();

        if (Cache::has($cacheKey)) {
            return redirect()->route('invoices')
                ->with('status', 'Sync is already in progress.');
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        SyncInvoicesJob::dispatch(auth()->user());

        return redirect()->route('invoices')
            ->with('status', 'Sync started.');
    }

    public function syncStatus()
    {
        return response()->json([
            'syncing' => Cache::has('sync_in_progress:' . auth()->id()),
        ]);
    }
}
```

### 3. `resources/views/invoices.blade.php` (modify)

Replace the placeholder with a full listing view. Key elements:

- **Header:** "Invoices" title with conditional "Syncing…" indicator or "Sync Now" button.
- **Flash message:** `session('status')` displayed below header.
- **Auto-polling:** Alpine.js `x-data` block that polls `/invoices/sync-status` every 3 seconds when `$syncing` is true, and reloads the page when syncing completes.
- **Table:** Three columns (Foodics Ref, Daftra ID, Created) inside a card matching the app's design language.
- **Empty state:** Friendly message when no invoices exist.
- **Pagination:** Uses a custom pagination view (see file #4).

```blade
@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
<div class="max-w-7xl mx-auto" x-data="{ syncing: {{ $syncing ? 'true' : 'false' }} }" x-init="
    if (syncing) {
        const poll = setInterval(async () => {
            const res = await fetch('{{ route('invoices.sync-status') }}');
            const data = await res.json();
            if (!data.syncing) {
                clearInterval(poll);
                window.location.reload();
            }
        }, 3000);
    }
">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">Invoices</h1>
        <template x-if="syncing">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                Syncing…
            </span>
        </template>
        <template x-if="!syncing">
            <form method="POST" action="{{ route('invoices.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m8.836 8.836A8 8 0 0118.582 9M4 4l5 5" />
                    </svg>
                    Sync Now
                </button>
            </form>
        </template>
    </div>

    @if(session('status'))
        <div class="mb-4 p-3 rounded-lg bg-[#F5F5F3] dark:bg-[#262625] text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
            {{ session('status') }}
        </div>
    @endif

    @if($invoices->count() > 0)
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Foodics Ref</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Daftra ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    @foreach($invoices as $invoice)
                        <tr class="hover:bg-[#F5F5F3] dark:hover:bg-[#262625] transition-colors">
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">{{ $invoice->foodics_reference }}</td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">{{ $invoice->daftra_id }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400">{{ ucfirst($invoice->status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $invoice->created_at->format('M d, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $invoices->withQueryString()->links('pagination::tailwind-custom') }}
        </div>
    @else
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6">
            <p class="text-[#706f6c] dark:text-[#A1A09A]">No invoices yet.</p>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mt-1">Sync your Foodics orders to see them here.</p>
        </div>
    @endif
</div>
@endsection
```

### 4. `resources/views/pagination/tailwind-custom.blade.php`

Custom pagination view matching the app's design language (see `layouts/app.blade.php` colors):

- Use the same color palette: borders `border-[#e3e3e0] dark:border-[#3E3E3A]`, text `text-[#1b1b18] dark:text-[#EDEDEC]`, muted `text-[#706f6c] dark:text-[#A1A09A]`.
- Active page: `bg-[#4A90D9] text-white` (Daftra blue matching the Sync Now button).
- Inactive/hover: `hover:bg-[#F5F5F3] dark:hover:bg-[#262625]`.
- Rounded buttons matching the app's `rounded-lg` style.
- Support dark mode with `dark:` variants.
- Base the structure on Laravel's default Tailwind pagination (`vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php`).

## Files to Modify

### 5. `routes/web.php`

- Change the invoices route from invokable to controller method: `Route::get('/invoices', [InvoiceController::class, 'index'])`.
- Add sync routes:
  ```php
  Route::post('/invoices/sync', [InvoiceController::class, 'sync'])->name('invoices.sync');
  Route::get('/invoices/sync-status', [InvoiceController::class, 'syncStatus'])->name('invoices.sync-status');
  ```

### 6. `app/Models/Invoice.php`

Add the `user` relationship:

```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

### 7. `app/Models/User.php`

Add the `invoices` relationship (if not present):

```php
public function invoices(): HasMany
{
    return $this->hasMany(Invoice::class);
}
```

## Design Details

### Colors & Styles
- Follows the `layouts/app.blade.php` design language:
  - Card background: `bg-white dark:bg-[#161615]`
  - Border: `border-[#e3e3e0] dark:border-[#3E3E3A]`
  - Muted text: `text-[#706f6c] dark:text-[#A1A09A]`
  - Primary text: `text-[#1b1b18] dark:text-[#EDEDEC]`
  - Hover row: `hover:bg-[#F5F5F3] dark:hover:bg-[#262625]`
- "Sync Now" button uses Daftra blue (`#4A90D9`).
- "Syncing…" state replaces the button with a disabled indicator + spinner icon.
- Flash message appears below the header using the `session('status')` pattern.

### Auto-Polling Behavior
- Alpine.js checks `$syncing` on page load (passed from the controller via `$syncing` variable).
- If `syncing` is `true`, starts polling `GET /invoices/sync-status` every 3 seconds.
- When `syncing` becomes `false`, clears the interval and calls `window.location.reload()` to show fresh invoice data.
- If `syncing` is `false` on load, no polling occurs (the "Sync Now" button is shown instead).

## Edge Cases

- **No invoices:** Show empty state message instead of an empty table.
- **Sync already in progress (controller):** Flash "Sync is already in progress." and redirect back without dispatching.
- **Sync already in progress (queue):** `ShouldBeUnique` silently prevents duplicate job dispatch if the controller check is bypassed.
- **Job failure:** Errors are logged per-order. The cache key is always cleared in the `finally` block, so the "Syncing…" state resolves even on job failure.
- **Job crashes before finally:** Cache key has a 5-minute TTL as a fallback, so the "Syncing…" state will resolve within 5 minutes at worst.
- **User with no Foodics token:** The job logs a warning and returns gracefully. The `finally` block still clears the cache key.
- **CSRF protection:** The sync form includes `@csrf`.

## Tasks

- [ ] Create `app/Jobs/SyncInvoicesJob.php` (queued, ShouldBeUnique, per-order error handling, cache clear in finally)
- [ ] Modify `app/Http/Controllers/InvoiceController.php` (change from invokable to `index`, `sync`, `syncStatus` methods)
- [ ] Modify `resources/views/invoices.blade.php` (table with pagination, sync button, syncing state with auto-polling, empty state)
- [ ] Create `resources/views/pagination/tailwind-custom.blade.php` (custom pagination matching app design)
- [ ] Modify `routes/web.php` (update invoices routes, add sync and sync-status routes)
- [ ] Add `invoices()` relationship to `User` model
- [ ] Add `user()` relationship to `Invoice` model
- [ ] Write feature test for `GET /invoices` (listing, empty state, syncing state)
- [ ] Write feature test for `POST /invoices/sync` (dispatch, duplicate prevention, auth required)
- [ ] Write feature test for `GET /invoices/sync-status` (returns correct JSON)
- [ ] Run `vendor/bin/pint --dirty --format agent`

## Out of Scope

- No filtering, searching, or sorting on the table (future enhancement).
- No individual invoice detail view.
- No deletion or status changes from the UI.
- No webhook-based automatic sync triggering (handled separately by `WebhooksController`).