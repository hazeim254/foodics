# Invoice Filters and Sorting

## Todo List

- [x] Create migration: add `total_price` and `daftra_no` columns with indexes and backfill
- [x] Update `Invoice` model: add to `$fillable` and `casts()`
- [x] Update `SyncOrder` service: write `total_price` + `daftra_no` to new columns, remove from metadata
- [x] Update `SyncCreditNote` service: write `total_price` to new column, remove from metadata
- [x] Update `InvoiceFactory`: add defaults for `total_price` and `daftra_no`
- [x] Update `invoices.blade.php`: read from `$invoice->total_price` and `$invoice->daftra_no`
- [x] Create `InvoiceFiltersRequest` form request
- [x] Create `InvoiceQueryBuilder` query class
- [x] Update `InvoiceController::index()` to use form request + query builder
- [x] Update `invoices.blade.php`: add filter bar UI
- [x] Update `invoices.blade.php`: add sortable table headers
- [x] Update existing tests for new column references
- [x] Add filter tests (status, type, amount, date, search)
- [x] Add sort tests (each sortable column, asc/desc)
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run full test suite and verify all pass

## Overview

Add filter and sorting capabilities to the invoices listing page (`/invoices`). This requires extracting commonly-filtered fields from JSON metadata to dedicated database columns for DB-agnostic queries across SQLite and PostgreSQL.

## Problem Statement

The current invoices listing page displays all invoices with no way to filter or sort. Users need to:
- Search by Foodics reference or Daftra invoice number
- Filter by amount range
- Filter by sync status (Pending, Failed, Synced)
- Filter by type (Invoice, Credit Note)
- Filter by date range (sync date)
- Sort by any column (ascending/descending)

The current implementation stores `total_price` and `daftra_no` inside JSON columns (`foodics_metadata`, `daftra_metadata`), which makes filtering DB-engine dependent.

## Goals

1. Add comprehensive filtering options to invoices listing
2. Add column sorting (ascending/descending)
3. Ensure filter/sort works across SQLite and PostgreSQL without JSON queries
4. Maintain backward compatibility for existing functionality

---

## Part 1: Database Schema Changes

### New Columns

Add two new columns to `invoices` table:

| Column       | Type                      | Description                                    |
| ------------ | ------------------------- | ---------------------------------------------- |
| `total_price` | `decimal(10,2) nullable`  | Extracted from `foodics_metadata->total_price` |
| `daftra_no`   | `string nullable`          | Extracted from `daftra_metadata->no`            |

### Migration

**File:** `database/migrations/XXXX_XX_XX_XXXXXX_add_invoice_filter_columns.php`

```php
Schema::table('invoices', function (Blueprint $table) {
    $table->decimal('total_price', 10, 2)->nullable()->after('daftra_id');
    $table->string('daftra_no', 50)->nullable()->after('total_price');
});

// Backfill existing data
DB::statement("
    UPDATE invoices
    SET total_price = CAST(json_extract(foodics_metadata, '$.total_price') AS REAL)
    WHERE foodics_metadata IS NOT NULL
");

DB::statement("
    UPDATE invoices
    SET daftra_no = json_extract(daftra_metadata, '$.no')
    WHERE daftra_metadata IS NOT NULL
");
```

**Indexes for filtering/sorting:**
```php
$table->index('total_price');
$table->index('daftra_no');
$table->index(['status', 'created_at']);
$table->index(['type', 'created_at']);
```

---

## Part 2: Model Changes

### `app/Models/Invoice.php`

**$fillable additions:**
```php
protected $fillable = [
    // ... existing ...
    'total_price',
    'daftra_no',
];
```

**casts() additions:**
```php
protected function casts(): array
{
    return [
        // ... existing ...
        'total_price' => 'decimal:2',
    ];
}
```

---

## Part 3: Service Layer Changes

### `app/Services/SyncOrder.php`

**Changes in `runSync()` (line ~88):**
```php
// Old
$invoice->update([
    'daftra_metadata' => [
        'no' => $daftraInvoice['no'] ?? null,
        'client_id' => $daftraInvoice['client_id'] ?? null,
    ],
]);

// New
$invoice->update([
    'daftra_no' => $daftraInvoice['no'] ?? null,
    'daftra_metadata' => [
        'client_id' => $daftraInvoice['client_id'] ?? null,
    ],
]);
```

**Changes in `createPendingInvoice()` (line ~230):**
```php
// Old
$invoice->fill([
    'foodics_metadata' => [
        'total_price' => (float) ($order['total_price'] ?? 0),
    ],
])->save();

// New
$invoice->fill([
    'total_price' => (float) ($order['total_price'] ?? 0),
    'foodics_metadata' => [],
])->save();
```

**Changes in create (line ~244):**
```php
// Old
return Invoice::query()->create([
    // ... existing fields ...
    'foodics_metadata' => [
        'total_price' => (float) ($order['total_price'] ?? 0),
    ],
]);

// New
return Invoice::query()->create([
    // ... existing fields ...
    'total_price' => (float) ($order['total_price'] ?? 0),
    'foodics_metadata' => [],
]);
```

### `app/Services/SyncCreditNote.php`

**Changes in `createPendingCreditNote()` (line ~140):**

In both the `if ($creditNote !== null)` block and the create block:

```php
// Old
$creditNote->fill([
    'foodics_metadata' => [
        'total_price' => (float) ($order['total_price'] ?? 0),
    ],
])->save();

// New
$creditNote->fill([
    'total_price' => (float) ($order['total_price'] ?? 0),
    'foodics_metadata' => [],
])->save();
```

And similarly in the create block:
```php
return Invoice::query()->create([
    // ... existing fields ...
    'total_price' => (float) ($order['total_price'] ?? 0),
    'foodics_metadata' => [],
]);
```

---

## Part 4: Factory Updates

### `database/factories/InvoiceFactory.php`

```php
public function definition(): array
{
    return [
        // ... existing ...
        'total_price' => fake()->randomFloat(2, 10, 1000),
        'daftra_no' => fake()->optional()->numerify('INV-#####'),
    ];
}
```

---

## Part 5: View Updates

### `resources/views/invoices.blade.php`

**Current display (lines ~69, ~82):**
```php
$daftraNo = $invoice->daftra_metadata['no'] ?? $invoice->daftra_id;
{{ $invoice->foodics_metadata['total_price'] ?? '—' }}
```

**Updated display:**
```php
$daftraNo = $invoice->daftra_no ?? $invoice->daftra_id;
{{ $invoice->total_price ?? '—' }}
```

---

## Part 6: Filter Request & Query Builder

### `app/Http/Requests/InvoiceFiltersRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:100',
            'foodics_ref' => 'nullable|string|max:100',
            'daftra_no' => 'nullable|string|max:50',
            'amount_from' => 'nullable|numeric|min:0',
            'amount_to' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:pending,failed,synced',
            'type' => 'nullable|string|in:invoice,credit_note',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'sort_by' => 'nullable|string|in:foodics_reference,daftra_no,total_price,status,created_at',
            'sort_dir' => 'nullable|string|in:asc,desc',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_to.min' => __('Amount must be greater than 0.'),
            'date_to.after_or_equal' => __('End date must be after start date.'),
        ];
    }
}
```

### `app/Queries/InvoiceQueryBuilder.php`

```php
<?php

namespace App\Queries;

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Builder;

class InvoiceQueryBuilder
{
    public function apply(Builder $query, array $filters): Builder
    {
        // Search (searches both foodics_reference and daftra_no)
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('foodics_reference', 'like', "%{$search}%")
                  ->orWhere('daftra_no', 'like', "%{$search}%");
            });
        }

        // Foodics Reference
        if (! empty($filters['foodics_ref'])) {
            $query->where('foodics_reference', 'like', "%{$filters['foodics_ref']}%");
        }

        // Daftra No
        if (! empty($filters['daftra_no'])) {
            $query->where('daftra_no', 'like', "%{$filters['daftra_no']}%");
        }

        // Amount range
        if (! empty($filters['amount_from'])) {
            $query->where('total_price', '>=', (float) $filters['amount_from']);
        }
        if (! empty($filters['amount_to'])) {
            $query->where('total_price', '<=', (float) $filters['amount_to']);
        }

        // Status
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Type
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Date range
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        // Status and type are enums - sort by raw value
        if (in_array($sortBy, ['status', 'type'])) {
            $query->orderByRaw("CAST({$sortBy} AS TEXT) " . strtoupper($sortDir));
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return $query;
    }
}
```

---

## Part 7: Controller Updates

### `app/Http/Controllers/InvoiceController.php`

```php
public function index(InvoiceFiltersRequest $request)
{
    $filters = $request->validated();

    $query = auth()->user()->invoices();

    $invoices = app(InvoiceQueryBuilder::class)
        ->apply($query, $filters)
        ->paginate(50)
        ->withQueryString();

    $syncing = Cache::has('sync_in_progress:'.auth()->id());

    return view('invoices', compact('invoices', 'syncing', 'filters'));
}
```

---

## Part 8: View UI Implementation

### `resources/views/invoices.blade.php`

**Filter Bar (collapsible, above table):**

```blade
<div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 mb-4">
    <form method="GET" action="{{ route('invoices') }}" class="space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Filters') }}</h3>
            @if(request()->hasAny(array_keys($filters ?? [])))
                <a href="{{ route('invoices') }}" class="text-sm text-[#4A90D9] hover:underline">{{ __('Clear') }}</a>
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Search -->
            <div>
                <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Search') }}</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                       placeholder="{{ __('Foodics Ref or Daftra No') }}"
                       class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
            </div>

            <!-- Status -->
            <div>
                <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Status') }}</label>
                <select name="status" class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                    <option value="failed" {{ ($filters['status'] ?? '') === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                    <option value="synced" {{ ($filters['status'] ?? '') === 'synced' ? 'selected' : '' }}>{{ __('Synced') }}</option>
                </select>
            </div>

            <!-- Type -->
            <div>
                <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Type') }}</label>
                <select name="type" class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="invoice" {{ ($filters['type'] ?? '') === 'invoice' ? 'selected' : '' }}>{{ __('Invoice') }}</option>
                    <option value="credit_note" {{ ($filters['type'] ?? '') === 'credit_note' ? 'selected' : '' }}>{{ __('Credit Note') }}</option>
                </select>
            </div>

            <!-- Amount Range -->
            <div class="flex gap-2">
                <div class="flex-1">
                    <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('From') }}</label>
                    <input type="number" name="amount_from" value="{{ $filters['amount_from'] ?? '' }}"
                           step="0.01" min="0" placeholder="0"
                           class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('To') }}</label>
                    <input type="number" name="amount_to" value="{{ $filters['amount_to'] ?? '' }}"
                           step="0.01" min="0" placeholder="9999"
                           class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                </div>
            </div>

            <!-- Date Range -->
            <div class="flex gap-2">
                <div class="flex-1">
                    <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Date From') }}</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Date To') }}</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors">
                {{ __('Apply Filters') }}
            </button>
        </div>
    </form>
</div>
```

**Sortable Table Headers:**

```blade
<th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
    @sortableLink('foodics_reference', __('Foodics Ref'))
</th>
<th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
    @sortableLink('daftra_no', __('Daftra Invoice'))
</th>
<th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
    @sortableLink('total_price', __('Total'))
</th>
<th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
    @sortableLink('status', __('Status'))
</th>
<th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
    @sortableLink('created_at', __('Created'))
</th>
```

**Helper directive for sortable links (add to AppServiceProvider or custom blade extension):**

```php
// In a blade service provider or AppServiceProvider boot()
Blade::directive('sortableLink', function ($expression) {
    $parts = explode(',', $expression);
    $column = trim($parts[0], "' ");
    $label = trim($parts[1], "' ");

    return "<?php
        \$currentSort = request('sort_by');
        \$currentDir = request('sort_dir', 'desc');
        \$isActive = \$currentSort === '{$column}';
        \$newDir = \$isActive && \$currentDir === 'asc' ? 'desc' : 'asc';
        \$url = '?' . http_build_query(array_merge(request()->query(), ['sort_by' => '{$column}', 'sort_dir' => \$newDir]));
    ?>
    <a href=\"<?php echo \$url; ?>\" class=\"flex items-center gap-1 hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]\">
        <?php echo e(__('{$label}')); ?>
        <?php if(\$isActive): ?>
            <svg class=\"w-3 h-3 <?php echo \$currentDir === 'asc' ? '' : 'rotate-180'; ?>\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 15l7-7 7 7\" />
            </svg>
        <?php endif; ?>
    </a>";
});
```

**Active filter indicators (below filter bar):**

```blade
@if($filters ?? [])
    <div class="flex flex-wrap gap-2 mb-4">
        @if(($filters['status'] ?? ''))
            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                {{ __('Status') }}: {{ __($filters['status']) }}
                <a href="{{ request()->fullUrlWithQuery(['status' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
            </span>
        @endif
        @if(($filters['type'] ?? ''))
            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                {{ __('Type') }}: {{ __($filters['type']) }}
                <a href="{{ request()->fullUrlWithQuery(['type' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
            </span>
        @endif
        @if(($filters['amount_from'] ?? ''))
            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                {{ __('Amount') }}: {{ $filters['amount_from'] }} - {{ $filters['amount_to'] ?? '∞' }}
                <a href="{{ request()->fullUrlWithQuery(['amount_from' => null, 'amount_to' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
            </span>
        @endif
        @if(($filters['date_from'] ?? ''))
            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                {{ __('Date') }}: {{ $filters['date_from'] }} - {{ $filters['date_to'] ?? '∞' }}
                <a href="{{ request()->fullUrlWithQuery(['date_from' => null, 'date_to' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
            </span>
        @endif
    </div>
@endif
```

---

## Part 9: Tests to Update

### `tests/Feature/InvoiceControllerTest.php`

Update all tests that reference `foodics_metadata['total_price']` or `daftra_metadata['no']`:

```php
// Old
'invoice' => [
    'foodics_metadata' => ['total_price' => 24.15],
    'daftra_metadata' => ['no' => 'INV-001'],
]

// New
'invoice' => [
    'total_price' => 24.15,
    'daftra_no' => 'INV-001',
]
```

### `tests/Feature/Services/SyncOrderTest.php`

Similar updates to assertions that check `foodics_metadata` and `daftra_metadata`.

### `tests/Feature/Services/SyncOrderReturnTest.php`

Similar updates.

### New tests to add:

**Filter tests:**
- `it('filters invoices by status')`
- `it('filters invoices by type')`
- `it('filters invoices by amount range')`
- `it('filters invoices by date range')`
- `it('searches invoices by foodics reference')`
- `it('searches invoices by daftra number')`
- `it('combines multiple filters')`
- `it('persists filters across pagination')`

**Sort tests:**
- `it('sorts invoices by foodics reference asc')`
- `it('sorts invoices by foodics reference desc')`
- `it('sorts invoices by total price asc')`
- `it('sorts invoices by created at asc')`
- `it('sorts invoices by status')`

---

## Implementation Order

1. Create migration with new columns, indexes, and backfill
2. Update `Invoice` model (fillable + casts)
3. Update `SyncOrder` service (write to new columns)
4. Update `SyncCreditNote` service (write to new columns)
5. Update `InvoiceFactory`
6. Update `invoices.blade.php` (display from new columns)
7. Create `InvoiceFiltersRequest`
8. Create `InvoiceQueryBuilder`
9. Update `InvoiceController`
10. Update `invoices.blade.php` (add filter bar + sortable headers)
11. Run `pint` to format code
12. Run tests, fix any failures
13. Update test assertions for new columns
14. Add new filter/sort tests

---

## Acceptance Criteria

1. ✓ User can search by Foodics reference
2. ✓ User can search by Daftra invoice number
3. ✓ User can filter by status (Pending/Failed/Synced)
4. ✓ User can filter by type (Invoice/Credit Note)
5. ✓ User can filter by amount range
6. ✓ User can filter by date range
7. ✓ User can sort by any column (ascending/descending)
8. ✓ Filters persist across pagination
9. ✓ Filters work identically on SQLite and PostgreSQL
10. ✓ Existing tests pass
11. ✓ New filter/sort tests pass

---

## Files to Create/Modify

| File | Action |
| ---- |--------|
| `database/migrations/XXXX_XX_XX_XXXXXX_add_invoice_filter_columns.php` | Create |
| `app/Models/Invoice.php` | Modify |
| `app/Services/SyncOrder.php` | Modify |
| `app/Services/SyncCreditNote.php` | Modify |
| `database/factories/InvoiceFactory.php` | Modify |
| `app/Http/Requests/InvoiceFiltersRequest.php` | Create |
| `app/Queries/InvoiceQueryBuilder.php` | Create |
| `app/Http/Controllers/InvoiceController.php` | Modify |
| `resources/views/invoices.blade.php` | Modify |
| `tests/Feature/InvoiceControllerTest.php` | Modify |
| `tests/Feature/Services/SyncOrderTest.php` | Modify |
| `tests/Feature/Services/SyncOrderReturnTest.php` | Modify |