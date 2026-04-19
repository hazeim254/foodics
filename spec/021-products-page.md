# 021 — Products Page with Resync Functionality

## Overview

Replace the placeholder products page with a functional listing that displays synced products in a table, a "Sync Now" button that triggers a bulk product sync from Foodics via a queued job, and a per-row "Resync" button for pending or failed products. The page mirrors the invoices page pattern: Alpine.js polling for sync-in-progress state, cache-based duplicate prevention, and `ShouldBeUnique` on the bulk job.

## Context

- The `products` table currently stores: `id`, `user_id`, `foodics_id`, `daftra_id` (not nullable), `status` (string, no enum cast), `timestamps`.
- `ProductController` is currently an invokable controller returning a placeholder view.
- `Product` model has no `HasFactory` trait, no `casts()`, no `user()` relationship.
- `Foodics\ProductService` has only `getProduct(string $productId)` — no bulk `fetchProducts()` method.
- `Daftra\ProductService::getProductByFoodicsData()` already handles local DB lookup, Daftra API lookup, and Daftra creation. Its `persistProduct()` method hardcodes `status => 'synced'` and stores no metadata.
- The invoice sync flow (specs 016, 017, 019) provides the proven pattern: cache key for UI state, `ShouldBeUnique` bulk job, per-row retry job, controller guard, Alpine.js polling, and a `SyncStatus` enum.
- Products are created during invoice sync as side-effects of `SyncOrder::getInvoiceItems()`. However, those product rows only store `foodics_id`, `daftra_id`, and `status = 'synced'` — no product name, SKU, or metadata. This spec introduces a dedicated product sync that populates richer metadata and supports independent product-level resync.

## Decisions

| Concern | Decision |
|----------|-----------|
| Product display name | Store in a `foodics_name` column for fast table queries rather than always deserializing JSON metadata |
| Product SKU | Store in a `foodics_sku` column (indexed, used for Daftra `product_code` resolution) |
| Make `daftra_id` nullable | Yes — products can fail before Daftra sync completes, mirroring invoices |
| Sync granularity | Bulk sync fetches all Foodics products via cursor pagination; per-product resync re-fetches a single product from Foodics |
| Daftra product resolution | Reuse existing `Daftra\ProductService::getProductByFoodicsData()` — no changes to its core lookup/create logic |
| Foodics product listing | New `fetchProducts()` method on `Foodics\ProductService` using `/v5/products` with cursor-based pagination, matching `OrderService::fetchNewOrders()` pattern |
| Cache key | `sync_products_in_progress:{user_id}` — separate from invoice sync to allow both to run independently |
| Enum reuse | Create a dedicated `ProductSyncStatus` enum rather than reusing `InvoiceSyncStatus`, to keep concerns separate |

## Requirements

### 1. Migration — Add metadata columns to products table

Add a new migration that:

- Makes `daftra_id` nullable.
- Adds `foodics_name` (string, after `foodics_id`).
- Adds `foodics_sku` (string, nullable, after `foodics_name`).
- Adds `foodics_metadata` (JSON, nullable, after `status`).
- Adds `daftra_metadata` (JSON, nullable, after `foodics_metadata`).
- Adds a composite index on `[user_id, foodics_id]`.
- Adds an index on `foodics_sku`.

### 2. Enum — `ProductSyncStatus`

```php
enum ProductSyncStatus: string
{
    case Pending = 'pending';
    case Failed  = 'failed';
    case Synced  = 'synced';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Synced  => 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400',
            self::Pending => 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400',
            self::Failed  => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
        };
    }
}
```

### 3. Model — `Product`

Update the `Product` model to:

- Add the `HasFactory` trait.
- Add all new columns to `$fillable`: `foodics_name`, `foodics_sku`, `foodics_metadata`, `daftra_metadata`.
- Add a `casts()` method:
  ```php
  protected function casts(): array
  {
      return [
          'daftra_id'         => 'integer',
          'status'            => ProductSyncStatus::class,
          'foodics_metadata'  => 'array',
          'daftra_metadata'  => 'array',
      ];
  }
  ```
- Add a `user()` relationship:
  ```php
  public function user(): BelongsTo
  {
      return $this->belongsTo(User::class);
  }
  ```

### 4. Service — `Foodics\ProductService::fetchProducts()`

Add a method to fetch all products from the Foodics API using cursor-based pagination (same pattern as `OrderService::fetchNewOrders()`):

```php
public function fetchProducts(): array
{
    $allProducts = [];
    $after = null;
    $hasMore = true;

    while ($hasMore) {
        $params = [
            'limit' => 50,
        ];

        if ($after !== null) {
            $params['after'] = $after;
        }

        $response = $this->client->get('/v5/products', $params);
        $response->throw();

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            $hasMore = false;
            continue;
        }

        $allProducts = array_merge($allProducts, $data);

        $meta = $response->json('meta') ?? [];
        $cursor = $meta['next_cursor'] ?? null;

        if ($cursor !== null) {
            $after = $cursor;
        } else {
            $hasMore = false;
        }
    }

    return $allProducts;
}
```

Note: The Foodics `/v5/products` endpoint supports cursor-based pagination via `limit` and `after` parameters. If the API returns page-based pagination instead, the implementation should follow that pagination style. The data shape per product is defined in `json-stubs/foodics/list-products.json`.

### 5. Service — `SyncProductService`

New service that handles syncing a single Foodics product to Daftra. Follows the same "duplicate guard → create pending → run sync" pattern as `SyncOrder`:

```php
class SyncProductService
{
    public function __construct(
        protected DaftraProductService $daftraProductService,
    ) {}

    /**
     * Sync a single Foodics product array to Daftra.
     */
    public function handle(array $foodicsProduct): void
    {
        try {
            $this->skipIfAlreadySynced((string) $foodicsProduct['id']);
        } catch (ProductAlreadyExistsException $e) {
            return;
        }

        $product = $this->createOrUpdatePending($foodicsProduct);

        try {
            $this->runSync($foodicsProduct, $product);
        } catch (Throwable $e) {
            $product->update(['status' => ProductSyncStatus::Failed]);
            throw $e;
        }
    }

    /**
     * @throws ProductAlreadyExistsException
     */
    protected function skipIfAlreadySynced(string $foodicsId): void
    {
        $userId = Context::get('user')?->id;

        $blocking = Product::query()
            ->where('user_id', $userId)
            ->whereIn('status', [ProductSyncStatus::Pending, ProductSyncStatus::Synced])
            ->where('foodics_id', $foodicsId)
            ->exists();

        throw_if($blocking, new ProductAlreadyExistsException('Product already synced or in progress locally'));
    }

    protected function createOrUpdatePending(array $foodicsProduct): Product
    {
        $userId = Context::get('user')?->id;
        $foodicsId = (string) $foodicsProduct['id'];

        $product = Product::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $foodicsId)
            ->first();

        $pendingData = [
            'foodics_name'     => (string) ($foodicsProduct['name'] ?? 'Unknown Product'),
            'foodics_sku'      => isset($foodicsProduct['sku']) && trim($foodicsProduct['sku']) !== ''
                                        ? trim((string) $foodicsProduct['sku'])
                                        : null,
            'status'           => ProductSyncStatus::Pending,
            'foodics_metadata' => $this->buildFoodicsMetadata($foodicsProduct),
        ];

        if ($product !== null) {
            $product->fill($pendingData)->save();
            return $product;
        }

        return Product::query()->create(array_merge([
            'user_id'    => $userId,
            'foodics_id' => $foodicsId,
            'daftra_id' => null,
        ], $pendingData));
    }

    protected function runSync(array $foodicsProduct, Product $product): void
    {
        $daftraId = $this->daftraProductService->getProductByFoodicsData($foodicsProduct);

        $product->update([
            'daftra_id'        => $daftraId,
            'status'           => ProductSyncStatus::Synced,
            'daftra_metadata' => $this->buildDaftraMetadata($daftraId),
        ]);
    }

    protected function buildFoodicsMetadata(array $foodicsProduct): array
    {
        return [
            'price'        => (float) ($foodicsProduct['price'] ?? 0),
            'cost'         => isset($foodicsProduct['cost']) ? (float) $foodicsProduct['cost'] : null,
            'is_active'    => (bool) ($foodicsProduct['is_active'] ?? true),
            'barcode'      => (string) ($foodicsProduct['barcode'] ?? ''),
            'category'    => $foodicsProduct['category']['name'] ?? null,
            'tax_group'   => $foodicsProduct['tax_group']['reference'] ?? null,
        ];
    }

    protected function buildDaftraMetadata(int $daftraId): array
    {
        return [
            'id' => $daftraId,
        ];
    }
}
```

Notes:
- `ProductAlreadyExistsException` follows the same pattern as `InvoiceAlreadyExistsException`.
- `createOrUpdatePending` revives an existing `Failed` row (flipping to `Pending`) or creates a new one, mirroring `SyncOrder::createPendingInvoice`.
- `runSync` delegates to the existing `Daftra\ProductService::getProductByFoodicsData()` which already handles local DB lookup, Daftra API search, and Daftra creation.
- The `daftra_id` assignment in `runSync` should be reconciled with the `persistProduct()` call inside `Daftra\ProductService`. Since `getProductByFoodicsData()` returns the `daftra_id` and also calls `persistProduct()` internally for new products, `runSync` assigns the returned ID to the product row. For existing products (already persisted locally), `getProductByFoodicsData()` returns the existing `daftra_id` without calling `persistProduct()` again.

### 6. Exception — `ProductAlreadyExistsException`

```php
class ProductAlreadyExistsException extends \RuntimeException
{
}
```

### 7. Job — `SyncProductsJob`

Mirror `SyncInvoicesJob`:

```php
class SyncProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

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
                Log::warning("SyncProductsJob: User #{$this->user->id} has no Foodics token.");
                return;
            }

            $products = app(FoodicsProductService::class)->fetchProducts();

            foreach ($products as $productData) {
                try {
                    app(SyncProductService::class)->handle($productData);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } finally {
            Cache::forget('sync_products_in_progress:' . $this->user->id);
        }
    }
}
```

### 8. Job — `RetryProductSyncJob`

Mirror `RetryInvoiceSyncJob`:

```php
class RetryProductSyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public Product $product) {}

    public function handle(): void
    {
        $user = $this->product->user;
        Context::add('user', $user);

        if (! $user->getFoodicsToken()) {
            Log::warning("RetryProductSyncJob: User #{$user->id} has no Foodics token.");
            $this->product->update(['status' => ProductSyncStatus::Failed]);
            return;
        }

        $foodicsProduct = app(FoodicsProductService::class)->getProduct($this->product->foodics_id);

        app(SyncProductService::class)->handle($foodicsProduct);
    }
}
```

### 9. Controller — `ProductController`

Convert from invokable to a multi-method controller:

```php
class ProductController extends Controller
{
    public function index()
    {
        $products = auth()->user()->products()
            ->orderByDesc('created_at')
            ->paginate(50);

        $syncing = Cache::has('sync_products_in_progress:' . auth()->id());

        return view('products', compact('products', 'syncing'));
    }

    public function sync()
    {
        $cacheKey = 'sync_products_in_progress:' . auth()->id();

        if (Cache::has($cacheKey)) {
            return redirect()->route('products')
                ->with('status', 'Product sync is already in progress.');
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        SyncProductsJob::dispatch(auth()->user());

        return redirect()->route('products')
            ->with('status', 'Product sync started.');
    }

    public function syncStatus()
    {
        return response()->json([
            'syncing' => Cache::has('sync_products_in_progress:' . auth()->id()),
        ]);
    }

    public function resync(Product $product)
    {
        if ($product->user_id !== auth()->id()) {
            abort(403);
        }

        if ($product->status === ProductSyncStatus::Synced) {
            return redirect()->route('products')
                ->with('status', 'This product is already synced.');
        }

        $product->update(['status' => ProductSyncStatus::Failed]);

        RetryProductSyncJob::dispatch($product);

        return redirect()->route('products')
            ->with('status', "Resyncing product {$product->foodics_name}…");
    }
}
```

### 10. Routes

Update `routes/web.php` to replace the single invokable route with named routes:

```php
Route::middleware('auth')->group(function () {
    // ... existing routes ...
    Route::get('/products', [ProductController::class, 'index'])->name('products');
    Route::post('/products/sync', [ProductController::class, 'sync'])->name('products.sync');
    Route::get('/products/sync-status', [ProductController::class, 'syncStatus'])->name('products.sync-status');
    Route::post('/products/{product}/resync', [ProductController::class, 'resync'])->name('products.resync');
});
```

### 11. View — `products.blade.php`

Replace the placeholder with a full listing view matching the invoices page design language. Key elements:

- **Header:** "Products" title with conditional "Syncing…" indicator or "Sync Now" button.
- **Flash message:** `session('status')` displayed via `<x-alert>`.
- **Auto-polling:** Alpine.js `x-data` block polling `/products/sync-status` every 3 seconds when `$syncing` is true, reloading on completion.
- **Table columns:**

| Column | Source | Format |
|--------|--------|--------|
| Name | `product.foodics_name` | Plain text |
| SKU | `product.foodics_sku` | Plain text, `—` if null |
| Daftra ID | `product.daftra_id` | Link to Daftra product (using user's `daftra_meta.subdomain`) if both subdomain and `daftra_id` exist, else plain text or `—` |
| Status | `product.status` | Badge with `badgeClasses()` |
| Created | `product.created_at` | `diffForHumans()` with title tooltip |
| Actions | — | "Resync" button for `Pending`/`Failed` products |

- **Empty state:** "No products yet. Sync your Foodics products to see them here."
- **Pagination:** `{{ $products->withQueryString()->links('pagination::tailwind-custom') }}`

```blade
@extends('layouts.app')

@section('title', 'Products')

@section('content')
<div class="max-w-7xl mx-auto" x-data="{ syncing: {{ $syncing ? 'true' : 'false' }} }" x-init="
    if (syncing) {
        const poll = setInterval(async () => {
            const res = await fetch('{{ route('products.sync-status') }}');
            const data = await res.json();
            if (!data.syncing) {
                clearInterval(poll);
                window.location.reload();
            }
        }, 3000);
    }
">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">Products</h1>
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
            <form method="POST" action="{{ route('products.sync') }}">
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
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    @if($products->count() > 0)
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">SKU</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Daftra ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    @foreach($products as $product)
                        <tr class="hover:bg-[#F5F5F3] dark:hover:bg-[#262625] transition-colors">
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                <a href="{{ config('services.foodics.base_url') }}/products/{{ $product->foodics_id }}" target="_blank" class="hover:underline">
                                    {{ $product->foodics_name }}
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">{{ $product->foodics_sku ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                @php
                                    $daftraSubdomain = auth()->user()?->daftra_meta['subdomain'] ?? null;
                                @endphp

                                @if($daftraSubdomain && $product->daftra_id)
                                    <a href="https://{{ $daftraSubdomain }}/owner/products/view/{{ $product->daftra_id }}" target="_blank" class="hover:underline">
                                        {{ $product->daftra_id }}
                                    </a>
                                @else
                                    {{ $product->daftra_id ?? '—' }}
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $product->status->badgeClasses() }}">{{ ucfirst($product->status->value) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#706f6c] dark:text-[#A1A09A]" title="{{ $product->created_at->toDateTimeString() }}">{{ $product->created_at->diffForHumans() }}</td>
                            <td class="px-6 py-4">
                                @if(in_array($product->status, [\App\Enums\ProductSyncStatus::Pending, \App\Enums\ProductSyncStatus::Failed]))
                                    <form method="POST" action="{{ route('products.resync', $product) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                            </svg>
                                            Resync
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $products->withQueryString()->links('pagination::tailwind-custom') }}
        </div>
    @else
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6">
            <p class="text-[#706f6c] dark:text-[#A1A09A]">No products yet.</p>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mt-1">Sync your Foodics products to see them here.</p>
        </div>
    @endif
</div>
@endsection
```

### 12. User Model — Add `products()` relationship

```php
public function products(): HasMany
{
    return $this->hasMany(Product::class);
}
```

### 13. Update `Daftra\ProductService::persistProduct()`

Update `persistProduct()` to accept and store the `status` and `foodics_name`/`foodics_sku` fields, so that when `SyncProductService` runs, the product row is populated correctly via `getProductByFoodicsData()`:

```php
private function persistProduct(int $userId, string $foodicsId, int $daftraId, string $status = 'synced', ?string $foodicsName = null, ?string $foodicsSku = null): void
{
    Product::query()->create([
        'user_id'       => $userId,
        'foodics_id'    => $foodicsId,
        'foodics_name'  => $foodicsName ?? 'Unknown Product',
        'foodics_sku'   => $foodicsSku,
        'daftra_id'     => $daftraId,
        'status'        => $status,
    ]);
}
```

The callers of `persistProduct()` (currently only from within `getProductByFoodicsData()`) should be updated to pass the product name and SKU from the `$foodicsProduct` array. This ensures products created as side-effects of invoice sync also get the enriched data.

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Product belongs to another user | 403 Forbidden |
| Product status is `Synced` | Redirect back with flash "This product is already synced." |
| User has no Foodics token | Job logs warning, sets product to `Failed` |
| Foodics API error during bulk sync | Per-product errors are caught and reported; remaining products continue; cache key still cleared in `finally` |
| Foodics API error during resync | Job retries up to 3 times; after exhausting tries, product stays `Failed` |
| Bulk sync already in progress | Flash "Product sync is already in progress." and redirect without dispatching |
| Bulk sync running while resync is dispatched | `SyncProductService::skipIfAlreadySynced` blocks if product is `Pending`/`Synced`; after bulk finishes, resync's duplicate guard will pass correctly because the row is `Synced` (or `Failed` if partially failed) |
| Resync clicked multiple times rapidly | Multiple `RetryProductSyncJob` instances dispatched; `SyncProductService` is idempotent for the same product |
| Product row already exists from invoice sync | `createOrUpdatePending` finds the existing row and updates it (fills `foodics_name`, `foodics_sku`, resets `status` to `Pending`) |
| Job crashes before `finally` | Cache key has 5-minute TTL as fallback; "Syncing…" state resolves within 5 minutes |

## Files to Create

1. `app/Enums/ProductSyncStatus.php`
2. `app/Exceptions/ProductAlreadyExistsException.php`
3. `app/Services/SyncProductService.php`
4. `app/Jobs/SyncProductsJob.php`
5. `app/Jobs/RetryProductSyncJob.php`
6. `database/migrations/2026_04_xx_xxxxxx_add_metadata_to_products_table.php`

## Files to Modify

1. `app/Models/Product.php` — add `HasFactory`, `casts()`, `user()` relationship, update `$fillable`
2. `app/Models/User.php` — add `products()` relationship
3. `app/Http/Controllers/ProductController.php` — convert from invokable to `index`, `sync`, `syncStatus`, `resync` methods
4. `app/Services/Foodics/ProductService.php` — add `fetchProducts()` method
5. `app/Services/Daftra/ProductService.php` — update `persistProduct()` signature to accept `status`, `foodicsName`, `foodicsSku`; update `getProductByFoodicsData()` callers
6. `routes/web.php` — replace invokable route with named routes
7. `resources/views/products.blade.php` — replace placeholder with full table view

## Tests

### `tests/Feature/ProductControllerTest.php`

- Guest cannot access products index (redirect to login)
- Guest cannot trigger product sync (redirect to login)
- Authenticated user sees products index with products
- Authenticated user sees empty state when no products
- Authenticated user can trigger product sync
- Authenticated user sees "already in progress" flash when sync is already running
- Authenticated user gets 403 when trying to resync another user's product
- Authenticated user sees "already synced" flash when resyncing a synced product
- Authenticated user can resync a pending product
- Authenticated user can resync a failed product
- The "Resync" button is visible on pending/failed rows and not on synced rows
- The "Sync Now" button is visible when not syncing
- The "Syncing…" indicator is visible when syncing

### `tests/Feature/ProductSyncTest.php`

- `SyncProductsJob` syncs products from Foodics and creates local rows
- `SyncProductsJob` skips products that are already synced
- `SyncProductsJob` sets product to `Failed` when Daftra sync throws
- `SyncProductsJob` clears cache key in `finally`
- `SyncProductsJob` returns gracefully when user has no Foodics token
- `SyncProductsJob` is `ShouldBeUnique` per user

### `tests/Feature/RetryProductSyncTest.php`

- `RetryProductSyncJob` re-syncs a single product from Foodics
- `RetryProductSyncJob` sets product to `Failed` when user has no Foodics token
- `RetryProductSyncJob` has `$tries = 3`

## Tasks

- [x] Create `app/Enums/ProductSyncStatus.php`
- [x] Create `app/Exceptions/ProductAlreadyExistsException.php`
- [x] Create migration to add `foodics_name`, `foodics_sku`, `foodics_metadata`, `daftra_metadata` columns and make `daftra_id` nullable on products table
- [x] Update `app/Models/Product.php` — `HasFactory`, `$fillable`, `casts()`, `user()` relationship
- [x] Update `app/Models/User.php` — add `products()` relationship
- [x] Create `app/Services/SyncProductService.php`
- [x] Create `app/Jobs/SyncProductsJob.php`
- [x] Create `app/Jobs/RetryProductSyncJob.php`
- [x] Update `app/Http/Controllers/ProductController.php` — convert to multi-method controller
- [x] Update `app/Services/Foodics/ProductService.php` — add `fetchProducts()`
- [x] Update `app/Services/Daftra/ProductService.php` — update `persistProduct()` signature and callers
- [x] Update `routes/web.php` — replace invokable route with named routes
- [x] Update `resources/views/products.blade.php` — replace placeholder with full table view
- [x] Write feature tests
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run tests to verify everything passes

## Out of Scope

- Product editing/updating in Daftra after initial sync (future: `updateProduct()` is already stubbed as empty)
- Product deletion or Daftra deletion sync
- Filtering, searching, or sorting on the products table
- Real-time WebSocket status updates (polling only, like invoices)
- Bulk resync (select multiple products and resync)
- Confirmation modal before resync
- `ShouldBeUnique` on the retry job (to keep initial implementation simple, like invoices)
- Rate limiting on the resync endpoint
- Updating products that already exist locally when their Foodics data has changed (re-sync always uses the latest Foodics data, but does not update an already-Synced row unless the user explicitly resyncs after a failure)