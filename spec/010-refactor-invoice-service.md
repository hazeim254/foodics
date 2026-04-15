# Refactor InvoiceService to Follow ProductService Pattern

## Context

`InvoiceService` is the only Daftra service that doesn't follow the established pattern used by `ProductService` and `ClientService`. It currently has:

- A `dd()` debug call left in `createInvoice()` (line 33)
- No response validation after invoice creation
- No custom exception on failure
- `doesFoodicsInvoiceExistInDaftra()` is hardcoded to `return false` (commented-out implementation)
- `getInvoice()` silently returns `null` on API failure instead of throwing
- `saveMapping()` uses `\Context::get()` with a leading backslash instead of importing `Context`
- `createInvoice()` returns `$response['id']` (array access on object) instead of `$response->json('id')`
- No `getInvoiceByFoodicsData()` orchestrator method (local DB check -> Daftra lookup -> fallback)

This means `SyncOrder::skipIfAlreadySyncedLocally()` always skips the Daftra-side duplicate check, and invoice creation errors go unhandled.

---

## Target Pattern (matching ProductService/ClientService)

All three services (`ProductService`, `ClientService`, `InvoiceService`) should follow the same structure:

1. **Orchestrator method** (`getXByFoodicsData`) — checks local DB, then Daftra API, then creates if needed
2. **Lookup method** (`getX`) — searches Daftra API, throws on failure, returns `?int`
3. **Create method** (`createX`) — creates on Daftra API, validates response, throws custom exception, returns `int`
4. **Persist method** (`persistX`) — saves local mapping
5. **Private helpers** — extract ID from API row, build payloads

---

## Changes

### 1. Create `DaftraInvoiceCreationFailedException`

**File:** `app/Exceptions/DaftraInvoiceCreationFailedException.php`

Follow the same shape as `DaftraProductCreationFailedException`:

```php
class DaftraInvoiceCreationFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to create invoice in Daftra.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

### 2. Create `InvoiceAlreadyExistsException`

**File:** `app/Exceptions/InvoiceAlreadyExistsException.php`

Replace the generic `\RuntimeException('Order already synced')` thrown in `SyncOrder::skipIfAlreadySyncedLocally()` with a typed exception:

```php
class InvoiceAlreadyExistsException extends RuntimeException
{
    public function __construct(
        string $message = 'Invoice already exists.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

---

### 2. Refactor `InvoiceService`

**File:** `app/Services/Daftra/InvoiceService.php`

#### a. Fix `getInvoice()` — throw on API failure instead of returning null

```php
public function getInvoice(string $foodicsId): ?array
{
    $response = $this->daftraClient->get('/api2/invoices', [
        'filter[po_number]' => $foodicsId,
    ]);

    if (! $response->successful()) {
        throw new \RuntimeException(
            'Daftra invoice list request failed: HTTP '.$response->status().' '.$response->body()
        );
    }

    $rows = $response->json('data') ?? [];
    if ($rows === []) {
        return null;
    }

    return $rows[0]['Invoice'] ?? null;
}
```

#### b. Fix `doesFoodicsInvoiceExistInDaftra()` — uncomment and delegate

```php
public function doesFoodicsInvoiceExistInDaftra(string $id): bool
{
    return $this->getInvoice($id) !== null;
}
```

#### c. Fix `createInvoice()` — remove `dd()`, validate response, throw exception, return `int`

```php
public function createInvoice(array $data): int
{
    $response = $this->daftraClient->post('/api2/invoices', $data);

    if ($response->failed()) {
        throw new DaftraInvoiceCreationFailedException(
            message: 'Daftra invoice creation failed: HTTP '.$response->status(),
            responseBody: $response->body(),
        );
    }

    $newId = $response->json('id');
    if ($newId === null || $newId === '') {
        throw new DaftraInvoiceCreationFailedException(
            message: 'Daftra invoice creation response missing id.',
            responseBody: $response->body(),
        );
    }

    return (int) $newId;
}
```

#### d. Fix `saveMapping()` — import `Context` facade, use `Invoice::query()`

```php
public function saveMapping(string $foodicsId, int $daftraId, string $foodicsReference): void
{
    Invoice::query()->create([
        'user_id' => Context::get('user')->id,
        'foodics_id' => $foodicsId,
        'daftra_id' => $daftraId,
        'foodics_reference' => $foodicsReference,
        'status' => 'synced',
    ]);
}
```

#### e. Add return type hints

- `createInvoice(array $data): int`
- `createPayment(int $daftraInvoiceId, array $paymentData): void`
- `saveMapping(...): void`
- `doesFoodicsInvoiceExistInDaftra(string $id): bool`
- `updateInvoice(int $id, array $data): bool`
- `deleteInvoice(int $id): bool`

---

### 3. Update `SyncOrder`

**File:** `app/Services/SyncOrder.php`

- `createInvoice()` now returns `int` — the `$daftraInvoiceId` variable on line 79 will be properly typed
- Replace `dd($e)` on line 42 with `return` (or `report($e); return`)
- `saveMapping()` now returns `void` — no change needed

#### Replace `\RuntimeException` in `skipIfAlreadySyncedLocally()` with `InvoiceAlreadyExistsException`

```php
protected function skipIfAlreadySyncedLocally($id): void
{
    $orderAlreadyExists = Invoice::query()->where('foodics_id', $id)->exists();
    throw_if($orderAlreadyExists, new InvoiceAlreadyExistsException('Order already synced locally'));

    $orderExistsOnDaftra = $this->invoiceService->doesFoodicsInvoiceExistInDaftra($id);
    throw_if($orderExistsOnDaftra, new InvoiceAlreadyExistsException('Order already synced on Daftra'));
}
```

#### Update `handle()` to catch `InvoiceAlreadyExistsException` explicitly

```php
public function handle(array $order): void
{
    try {
        $this->skipIfAlreadySyncedLocally($order['id']);
    } catch (InvoiceAlreadyExistsException $e) {
        return;
    }
    // ... rest unchanged
}
```

---

## TODO List

- [x] Create `app/Exceptions/DaftraInvoiceCreationFailedException.php`
- [x] Create `app/Exceptions/InvoiceAlreadyExistsException.php`
- [x] Refactor `app/Services/Daftra/InvoiceService.php` (items a–e above)
- [x] Update `app/Services/SyncOrder.php` — replace `dd($e)`, use `InvoiceAlreadyExistsException`
- [x] Update existing tests in `tests/Feature/Services/SyncOrderTest.php` to reflect new exceptions
- [x] Add unit tests for `InvoiceService` (creation failure, missing id, successful creation, duplicate detection)
- [x] Run `php artisan test --compact`
- [x] Run `vendor/bin/pint --dirty`

---

## Notes

- **No new public methods** — `InvoiceService` doesn't need a `getInvoiceByFoodicsData()` orchestrator because the invoice flow is different: `SyncOrder` handles duplicate detection itself (local check + `doesFoodicsInvoiceExistInDaftra`), and invoices are always created (never looked up and reused). The refactor focuses on robustness and consistency, not adding an orchestrator.
- The `createPayment()` method stays as-is — it's a fire-and-forget call with no return value needed.
- `updateInvoice()` and `deleteInvoice()` remain stubs (empty implementations can be added later).

---

## References

- `app/Services/Daftra/ProductService.php` — reference pattern
- `app/Services/Daftra/ClientService.php` — reference pattern
- `app/Exceptions/DaftraProductCreationFailedException.php` — exception pattern to follow
- `spec/001-sync-order-test.md` — existing E2E test that may need updates
