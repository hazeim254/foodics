# ProductService Implementation Plan

## Goal
Implement `ProductService` following the same pattern as `ClientService`, enabling sync of Foodics products to Daftra products.

## Context
- `SyncOrder::getInvoiceItems()` (line 69) calls `$this->productService->getProductByFoodicsData($orderProduct['product'])` — this method doesn't exist yet.
- `ClientService` follows a 3-step pattern: local DB lookup → Daftra API lookup → Daftra API create, with local persistence.
- The Daftra Product API mirrors the Client API: list with `GET /api2/products.json`, create with `POST /api2/products.json` (returns HTTP 202 with `{code, result, id}`).
- No `Product` model or `products` migration exists yet.

---

## Implementation Steps

### 1. Create `DaftraProductCreationFailedException`
**File:** `app/Exceptions/DaftraProductCreationFailedException.php`

Mirror `DaftraClientCreationFailedException`:
```php
class DaftraProductCreationFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to create product in Daftra.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

### 2. Create `Product` model
**File:** `app/Models/Product.php`

Mirror `Client` model:
```php
class Product extends Model
{
    protected $fillable = ['user_id', 'foodics_id', 'daftra_id', 'status'];
}
```

### 3. Create `products` migration
**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_products_table.php`

Mirror `clients` migration:
```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('foodics_id')->index();
    $table->integer('daftra_id');
    $table->string('status');
    $table->timestamps();
});
```

### 4. Daftra product JSON stubs (already created)

**Files:**
- `json-stubs/daftra/create-product.json` — Daftra create product payload structure (wraps under `Product` key)
- `json-stubs/daftra/list-products.json` — Daftra list products response structure (rows under `data[].Product`)

Create stub shows payload shape `{ Product: { staff_id, name, description, unit_price, buy_price, product_code, barcode, status, type, ... } }`.

List stub confirms response format: `{ code: 200, result: "successful", data: [{ Product: { id, ... } }], pagination: {...} }`.

Key field mapping (Foodics → Daftra):
| Foodics field | Daftra field | Notes |
|---|---|---|
| `id` (UUID) | Used for filtering via `product_code` | Fallback for `product_code` when `sku` is empty |
| `sku` | `product_code` | Primary mapping; falls back to `id` if `sku` is empty |
| `name` | `name` | |
| `description` | `description` | |
| `price` | `unit_price` | |
| `cost` | `buy_price` | Nullable |
| `barcode` | `barcode` | |
| `is_active` | `status` | `true`→0 (Active), `false`→1 (Inactive) |

### 5. Implement `ProductService`
**File:** `app/Services/Daftra/ProductService.php`

Methods to implement:

#### `getProductByFoodicsData(array $foodicsProduct): int`
Main orchestration method (mirrors `getClientUsingFoodicsData`):
1. Query local `Product` model by `user_id` + `foodics_id`. If found, return `daftra_id`.
2. Call `getProduct()` to search Daftra. If found, persist locally and return `daftra_id`.
3. Call `createProduct()`, persist locally, return `daftra_id`.

#### `getProduct(array $foodicsProduct): ?int`
- GET `/api2/products.json` with `filter[product_code]` set to the Foodics `sku` (or `id` if `sku` is empty).
- Parse `data[0].Product.id` from response.
- Return `null` if no results, throw `RuntimeException` on failure.

#### `createProduct(array $foodicsProduct): int`
- Build payload via `buildCreatePayload()`.
- POST `/api2/products.json`.
- Expect HTTP 202, throw `DaftraProductCreationFailedException` on failure.
- Extract and return `id` from response.

#### `updateProduct()` / `deleteProduct()`
- Leave as stubs (same as `ClientService`).

#### `persistProduct(int $userId, string $foodicsId, int $daftraId): void`
- Create `Product` record with `user_id`, `foodics_id`, `daftra_id`, `status='synced'`.

#### `buildCreatePayload(array $foodicsProduct, string $foodicsId): array`
- Transform Foodics product data into Daftra `{Product: {...}}` payload structure.
- Map: `product_code` = `sku` if present, otherwise `foodicsId` (UUID); `name`, `description`, `unit_price`, `buy_price`, `barcode`, `staff_id=0`, `type=1`, `status`.

#### `daftraProductIdFromListRow(array $row): int`
- Extract `Product.id` from list response row (mirrors `daftraClientIdFromListRow`).

---

## Daftra API Reference

### List Products
- **Endpoint:** `GET /api2/products.json`
- **Filter:** `filter[product_code]={foodicsId}`
- **Response:** `{ code: 200, result: "successful", data: [{ Product: { id, name, ... } }], pagination: {...} }`

### Create Product
- **Endpoint:** `POST /api2/products.json`
- **Request body:** `{ Product: { name, unit_price, product_code, ... } }`
- **Success response:** HTTP 202 `{ code: 202, result: "successful", id: "2415" }`
- **Error response:** HTTP 400 `{ result: "failed", code: 400, message: "Bad Request", validation_errors: {...} }`

### ProductBase fields available in Daftra
`name`, `description`, `unit_price`, `tax1`, `tax2`, `supplier_id`, `brand`, `tags`, `buy_price`, `product_code`, `track_stock`, `stock_balance`, `barcode`, `notes`, `status` (0=Active, 1=Inactive, 2=Suspended), `type` (1=Product, 2=Service, 3=Bundle), `staff_id`, `discount`, `discount_type`, `minimum_price`, `profit_margin`, `duration_minutes`, `availabe_online`

---

## File Change Summary
| Action | File |
|---|---|
| Create | `app/Exceptions/DaftraProductCreationFailedException.php` |
| Create | `app/Models/Product.php` |
| Create | `database/migrations/*_create_products_table.php` |
| Modify | `app/Services/Daftra/ProductService.php` |

---

## Todo
- [x] Create `DaftraProductCreationFailedException`
- [x] Create `Product` model with `fillable = ['user_id', 'foodics_id', 'daftra_id', 'status']`
- [x] Create `products` migration (mirror `clients` table)
- [x] Run migration
- [x] Implement `ProductService::getProductByFoodicsData(array $foodicsProduct): int`
- [x] Implement `ProductService::getProduct(array $foodicsProduct): ?int`
- [x] Implement `ProductService::createProduct(array $foodicsProduct): int`
- [x] Implement `ProductService::buildCreatePayload(array $foodicsProduct, string $foodicsId): array`
- [x] Implement `ProductService::daftraProductIdFromListRow(array $row): int`
- [x] Implement `ProductService::persistProduct(int $userId, string $foodicsId, int $daftraId): void`
- [x] Implement `ProductService::resolveProductCode(array $foodicsProduct, string $foodicsId): string`
