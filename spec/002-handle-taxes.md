# Handle Taxes: Foodics → Daftra Sync

## Problem

The `SyncOrder` service does not currently map Foodics taxes to Daftra invoice line items. Foodics orders carry tax data on products, modifier options, and charges, but the `InvoiceItem` payload sent to Daftra leaves `tax1` and `tax2` as `null`. This means invoices are created without any tax line items in Daftra.

---

## Analysis

### Foodics Tax Structure

Taxes appear in **three** places within a Foodics order (`json-stubs/foodics/get-order.json`):

1. **Product-level taxes** — `order.products[].taxes[]`
2. **Modifier option taxes** — `order.products[].options[].taxes[]`
3. **Charge taxes** — `order.charges[].taxes[]`

Each tax object:

```json
{
  "id": "8d84bebc",
  "name": "VAT",
  "name_localized": null,
  "rate": 5,
  "pivot": {
    "amount": 0.525,
    "rate": 5
  }
}
```

Key fields: `id` (Foodics tax ID), `name`, `rate` (percentage).

### Daftra Tax API

| Endpoint | Method | Purpose |
|---|---|---|
| `/api2/taxes.json` | GET | List all taxes (supports `filter` and pagination) |
| `/api2/taxes.json` | POST | Create a new tax |
| `/api2/taxes/{id}` | PUT | Update a tax |
| `/api2/taxes/{id}` | DELETE | Delete a tax |

**Tax schema** (Daftra `TaxBase`):

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | read-only | Auto-generated ID |
| `tax_id` | integer | read-only | Tax group ID |
| `name` | string | **yes** | Name of the tax |
| `value` | double | **yes** | Percentage value |
| `description` | string | no | Optional description |
| `included` | boolean | no | `0` = exclusive, `1` = inclusive. Default: `0` |

**InvoiceItem tax fields** (from `json-stubs/daftra/create-invoice.json`):

| Field | Type | Description |
|---|---|---|
| `tax1` | integer\|null | Daftra tax ID for the first tax slot |
| `tax2` | integer\|null | Daftra tax ID for the second tax slot |

Daftra supports **exactly two tax slots** per invoice line item (`tax1`, `tax2`). Each references a Daftra tax ID.

### Mapping Strategy

A Foodics order often has the **same tax** applied to multiple products/options. For example, a 5% VAT tax with `id = "8d84bebc"` appears on every product and option in the stub. We should:

1. **Extract unique taxes** from the entire order (deduplicate by Foodics tax ID).
2. **Resolve each unique tax** to a Daftra tax ID using the `entity_mappings` cache.
3. If not locally cached, **search Daftra** for an existing tax (by name + rate).
4. If not found in Daftra, **create** the tax in Daftra.
5. **Cache the mapping** in `entity_mappings` for future syncs.
6. Apply the resolved Daftra tax IDs when building `InvoiceItem.tax1` / `tax2`.

### Constraint: Daftra has only `tax1` and `tax2`

Since Daftra only supports 2 tax slots per invoice item, we map only the first two unique taxes per item, skip the rest, and log a warning.

---

## Implementation Plan

### 1. Create `entity_mappings` Migration

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_create_entity_mappings_table.php`

```php
Schema::create('entity_mappings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('type');               // 'tax', 'payment_method', etc.
    $table->string('foodics_id')->index();
    $table->integer('daftra_id');
    $table->json('metadata')->nullable();  // {"name": "VAT", "rate": 5}
    $table->string('status')->default('synced');
    $table->timestamps();

    $table->unique(['user_id', 'type', 'foodics_id']);
});
```

### 2. Create `EntityMapping` Model

**File**: `app/Models/EntityMapping.php`

```php
class EntityMapping extends Model
{
    protected $fillable = [
        'user_id', 'type', 'foodics_id', 'daftra_id', 'metadata', 'status',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function scopeOfType($query, string $type): void
    {
        $query->where('type', $type);
    }
}
```

### 3. Create `TaxService` (under `App\Services\Daftra`)

**File**: `app/Services/Daftra/TaxService.php`

Follows the same pattern as `ProductService` and `ClientService`:

```
resolveTaxId(array $foodicsTax): int
  1. Look up EntityMapping where user_id = current user, type = 'tax', foodics_id = $foodicsTax['id']
  2. If found → return daftra_id
  3. Search Daftra GET /api2/taxes.json?filter[name]=<name>
  4. If found → persist mapping, return daftra_id
  5. Create Daftra POST /api2/taxes.json { "Tax": { "name": ..., "value": ..., "included": 0 } }
  6. Persist mapping, return daftra_id
```

**Creating a tax payload:**

```json
{
  "Tax": {
    "name": "VAT",
    "value": 5,
    "included": 0
  }
}
```

- `included = 0` (exclusive) because Foodics tax `rate` represents an additional percentage on top of the price.
- `value` is the Foodics `rate` field (e.g., `5` for 5%).

### 4. Update `SyncOrder` Service

**File**: `app/Services/SyncOrder.php`

Changes:

1. Inject `TaxService` into the constructor.
2. Add a class-level property `array $taxMap = []` to hold the resolved Foodics→Daftra tax ID mappings for the current sync.
3. Add `resolveUniqueTaxes(array $order): void` — collects all unique taxes from `products[].taxes`, `products[].options[].taxes`, and `charges[].taxes`, deduplicates by Foodics tax `id`, populates `$this->taxMap` with `foodics_tax_id => daftra_tax_id`.
4. Update `getInvoiceItems()` to use `$this->taxMap` and populate `tax1` and `tax2`:

```php
$taxes = $orderProduct['taxes'] ?? [];
$daftraTaxIds = collect($taxes)
    ->pluck('id')
    ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
    ->filter()
    ->values()
    ->take(2);

$invoiceItems[] = [
    // ... existing fields ...
    'tax1' => $daftraTaxIds->get(0),
    'tax2' => $daftraTaxIds->get(1),
];
```

5. Update `handle()` to populate the tax map before creating invoice items:

```php
$this->taxMap = [];
$this->resolveUniqueTaxes($order);
$invoiceItems = $this->getInvoiceItems($order['products']);
```

### 5. Handle Charges with Taxes

Charges (e.g., "Service Charge") carry taxes and should be synced as separate `InvoiceItem` entries. Uses `$this->taxMap`:

```php
foreach ($order['charges'] ?? [] as $charge) {
    $taxes = $charge['taxes'] ?? [];
    $daftraTaxIds = collect($taxes)
        ->pluck('id')
        ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
        ->filter()
        ->values()
        ->take(2);

    $invoiceItems[] = [
        'item' => $charge['charge']['name'],
        'quantity' => 1,
        'unit_price' => $charge['amount'],
        'tax1' => $daftraTaxIds->get(0),
        'tax2' => $daftraTaxIds->get(1),
    ];
}
```

---

## Data Flow

```
Foodics Order
│
├── Collect all unique taxes (products, options, charges)
│   └── For each unique tax:
│       ├── Check entity_mappings (type='tax', foodics_id)
│       │   └── Found → use cached daftra_id
│       ├── Not cached → Search Daftra GET /api2/taxes.json
│       │   └── Found → save to entity_mappings, use daftra_id
│       └── Not found → Create Daftra POST /api2/taxes.json
│           └── Created → save to entity_mappings, use daftra_id
│
├── Build InvoiceItem[] with tax1, tax2 populated
│
└── POST /api2/invoices (with tax data)
```

---

## Files to Create/Modify

| Action | File |
|---|---|
| Create | `database/migrations/YYYY_create_entity_mappings_table.php` |
| Create | `app/Models/EntityMapping.php` |
| Create | `database/factories/EntityMappingFactory.php` |
| Create | `app/Services/Daftra/TaxService.php` |
| Modify | `app/Services/SyncOrder.php` |
| Create | `tests/Feature/Services/TaxServiceTest.php` |
| Create | `tests/Feature/Services/SyncOrderTaxTest.php` |

---

## TODO List

- [x] Create `entity_mappings` migration
- [x] Create `EntityMapping` model with `scopeOfType`
- [x] Create `EntityMappingFactory`
- [x] Create `TaxService` with `resolveTaxId`, `getTax`, `createTax` methods
- [x] Update `SyncOrder` constructor to inject `TaxService`
- [x] Add `resolveUniqueTaxes()` method to `SyncOrder`
- [x] Update `SyncOrder::getInvoiceItems()` to accept and apply `$taxMap`
- [x] Add charge-to-invoice-item conversion in `SyncOrder::handle()`
- [x] Create `TaxServiceTest` feature test
- [x] Create `SyncOrderTaxTest` feature test (E2E with mocked DaftraApiClient)
- [x] Run `php artisan test` to verify all tests pass
- [x] Run `vendor/bin/pint --dirty`

---

## Open Questions

1. **Tax inclusivity** — Foodics doesn't explicitly mark taxes as inclusive/exclusive. Assuming `included = 0` (exclusive) for all Daftra tax creation. Should this be configurable per user/account?
2. **Tax on charges** — Should service charges be synced as separate invoice items, or ignored? The plan above includes them.
3. **More than 2 taxes** — If a Foodics product has 3+ taxes, the plan maps only the first 2 and logs a warning. Is this acceptable?

---

## References

- `json-stubs/foodics/get-order.json` — Foodics order with tax data on products, options, and charges
- `json-stubs/daftra/create-invoice.json` — Daftra invoice stub with `tax1`/`tax2` fields
- Daftra GET All Taxes API: `GET /api2/taxes.json` — https://docs.daftara.dev/15115346e0
- Daftra Add New Tax API: `POST /api2/taxes.json` — https://docs.daftara.dev/15115347e0
- `app/Services/SyncOrder.php` — Current sync service to be updated
- `app/Services/Daftra/ProductService.php` — Pattern to follow for `TaxService`
- `app/Services/Daftra/ClientService.php` — Pattern to follow for `TaxService`
