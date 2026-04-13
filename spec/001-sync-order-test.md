# E2E Test for SyncOrder Service

## Context

The `SyncOrder` service (`app/Services/SyncOrder.php`) orchestrates the synchronization of orders from Foodics to Daftra. It handles:

1. **Duplicate detection** - Skips orders already synced locally or on Daftra
2. **Product resolution** - Maps Foodics products to Daftra product IDs via `ProductService`
3. **Client resolution** - Maps Foodics customers to Daftra client IDs via `ClientService`
4. **Invoice creation** - Creates a Daftra invoice via `InvoiceService`
5. **Payment sync** - Records payments against the created Daftra invoice

This test verifies the entire flow with **mocked `DaftraApiClient`** to ensure `SyncOrder` correctly:
- Builds invoice payloads with correct structure
- Resolves product and client IDs
- Persists the Foodics-to-Daftra ID mapping

---

## Approach

### Why Mock `DaftraApiClient`?

Mocking at the HTTP client level (`DaftraApiClient`) provides:

- **True E2E confidence** - Services are exercised as in production
- **Payload verification** - We can inspect exact API payloads sent to Daftra
- **Response handling** - Tests how `SyncOrder` handles API responses
- **No external dependencies** - No real HTTP calls to Daftra
---

## Test Data

### Source: `json-stubs/foodics/get-order.json`

### Expected API Calls (Mocked Responses)

| Service | Method | Path | Filter/Payload | Mock Response |
|---------|--------|------|----------------|---------------|
| InvoiceService | `get` | `/api2/invoices` | `po_number` | `[]` (not found) |
| ProductService | `get` | `/api2/products.json` | `product_code` | `[]` (not found) |
| ProductService | `post` | `/api2/products.json` | Create payload | `['id' => 67890]` |
| ClientService | `get` | `/api2/clients.json` | `client_number` | `[]` (not found) |
| ClientService | `post` | `/api2/clients.json` | Create payload | `['id' => 11111]` |
| InvoiceService | `post` | `/api2/invoices` | Invoice payload | `['data' => ['id' => 12345]]` |
| InvoiceService | `post` | `/api2/invoices/12345/payments` | Payment payload | `[]` |

### Expected Invoice Payload Structure

```php
[
    'Invoice' => [
        'po_number' => 'ebf8baa4-c847-41ad-8f04-198f2ee74dc0',  // Foodics order ID
        'client_id' => 11111,  // Daftra client ID
        'date' => '2019-11-28',
        'discount_amount' => 5,
        'notes' => 'Some Kitchen Notes 73664',
    ],
    'InvoiceItem' => [
        [
            'product_id' => 67890,
            'item' => 'Tuna Sandwich',
            'quantity' => 2,
            'unit_price' => 14,
            'discount' => 20,
            'discount_type' => 1,
        ]
    ],
]
```

### Expected Payment Payload

```php
[
    'payment_method' => 'Card',
    'amount' => 24.15,
    'date' => '2019-11-28 06:07:00',
]
```

---

## Files to Create

### 1. `database/factories/InvoiceFactory.php`

Factory for `Invoice` model (used to verify database persistence).

```php
public function definition(): array
{
    return [
        'user_id' => User::factory(),
        'foodics_id' => fake()->uuid(),
        'daftra_id' => fake()->randomNumber(5),
    ];
}
```

### 2. `database/factories/ClientFactory.php`

Factory for `Client` model (used by `ClientService` to persist mappings).

```php
public function definition(): array
{
    return [
        'user_id' => User::factory(),
        'foodics_id' => fake()->uuid(),
        'daftra_id' => fake()->randomNumber(5),
        'status' => 'synced',
    ];
}
```

### 3. `database/factories/ProductFactory.php`

Factory for `Product` model (used by `ProductService` to persist mappings).

```php
public function definition(): array
{
    return [
        'user_id' => User::factory(),
        'foodics_id' => fake()->uuid(),
        'daftra_id' => fake()->randomNumber(5),
        'status' => 'synced',
    ];
}
```

### 4. `tests/Feature/Services/SyncOrderTest.php`

Main E2E test file.

**Test flow:**

1. Create test user and set `Context::set('user', $user)`
2. Instantiate mock `DaftraApiClient`
3. Configure mock responses for each expected API call
4. Bind mock to Laravel container via `$this->app->instance(DaftraApiClient::class, $mock)`
5. Instantiate `SyncOrder` with real `InvoiceService`, `ProductService`, `ClientService` (all using the mocked `DaftraApiClient`)
6. Load order data from `json-stubs/foodics/get-order.json`
7. Call `$syncOrder->handle($order)`
8. Assert `Invoice` record exists with correct `foodics_id` and `daftra_id`

**Assertions:**
- `Invoice` with `foodics_id` = order ID and `daftra_id` = 12345 exists in database
- `Client` was created in Daftra and mapped locally
- `Product` was created in Daftra and mapped locally

---

## TODO List

- [x] Create `database/factories/InvoiceFactory.php`
- [x] Create `database/factories/ClientFactory.php`
- [x] Create `database/factories/ProductFactory.php`
- [x] Create `tests/Feature/Services/SyncOrderTest.php`
- [x] Run `php artisan test tests/Feature/Services/SyncOrderTest.php`
- [x] Run `vendor/bin/pint --dirty`

---

## Notes

### Out of Scope

- **Combos** - The stub contains combo data, but `SyncOrder::getInvoiceItems()` only iterates over the `products` array. Combo processing is handled separately.
- **Charges/Taxes** - Not processed by `SyncOrder::handle()`
- **Multiple payments** - Stub has 1 payment; code handles multiple but test validates single payment path

---

## References

- `app/Services/SyncOrder.php` - Service under test
- `app/Services/Daftra/DaftraApiClient.php` - HTTP client being mocked
- `app/Services/Daftra/InvoiceService.php` - Invoice operations
- `app/Services/Daftra/ProductService.php` - Product operations
- `app/Services/Daftra/ClientService.php` - Client operations
- `json-stubs/foodics/get-order.json` - Test data source
- `json-stubs/daftra/create-invoice.json` - Expected invoice structure reference
