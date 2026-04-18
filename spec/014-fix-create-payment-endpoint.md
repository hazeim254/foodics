# Fix Daftra `createPayment` Endpoint

## Context

`App\Services\Daftra\InvoiceService::createPayment()` currently posts to a
non-existent Daftra endpoint and the payload shape does not match what the
Daftra API expects. Per the official docs for
[Add New Invoice Payment](https://docs.daftara.dev/15115307e0), payments are
created against a top-level `invoice_payments` resource — not nested under an
invoice — and the request body is wrapped in an `InvoicePayment` object.

### Current (incorrect) implementation

```php
// app/Services/Daftra/InvoiceService.php (lines 86-89)
public function createPayment(int $daftraInvoiceId, array $paymentData): void
{
    $this->daftraClient->post("/api2/invoices/$daftraInvoiceId/payments", $paymentData);
}
```

Problems:

- URL `/api2/invoices/{id}/payments` is not a Daftra endpoint.
- Payload is not wrapped in the `InvoicePayment` envelope.
- `invoice_id` is passed as a URL segment rather than a body field (the Daftra
  API expects it inside the payload).

### Daftra spec summary

- `POST /api2/invoice_payments`
- Request body:

  ```json
  {
    "InvoicePayment": {
      "invoice_id": 123,
      "payment_method": "cash",
      "amount": 100.0,
      "date": "2024-01-01 12:00:00"
      // ... optional fields (transaction_id, treasury_id, currency_code, ...)
    }
  }
  ```

- Required fields: `invoice_id`, `payment_method`, `amount`.
- Success response: `202 Accepted` with `{ code, result, id }`.

### Convention alignment

`createInvoice(array $data)` in the same service already expects the caller to
pre-wrap the payload with the top-level resource key (see
[tests/Feature/Services/Daftra/InvoiceServiceTest.php](tests/Feature/Services/Daftra/InvoiceServiceTest.php)
line 84 — `['Invoice' => ['po_number' => 'order-1']]`). `createPayment` will
follow the same convention with an `'InvoicePayment'` wrapper.

---

## Changes

### 1. `app/Services/Daftra/InvoiceService.php`

Replace the existing method with a single-argument version that posts to the
correct endpoint. The caller supplies the pre-wrapped payload including
`invoice_id`:

```php
public function createPayment(array $data): void
{
    $this->daftraClient->post('/api2/invoice_payments', $data);
}
```

### 2. `app/Services/SyncOrder.php`

Update `syncPayment()` (lines 201-214) so `invoice_id` lives inside the payload
instead of being passed as a separate argument:

```php
public function syncPayment($payments, mixed $daftraInvoiceId): void
{
    foreach ($payments as $payment) {
        $foodicsPaymentMethodId = (string) ($payment['payment_method']['id'] ?? '');
        $daftraPaymentMethodId = $this->paymentMethodMap[$foodicsPaymentMethodId] ?? null;

        $this->invoiceService->createPayment([
            'InvoicePayment' => [
                'invoice_id' => $daftraInvoiceId,
                'payment_method' => $daftraPaymentMethodId,
                'amount' => $payment['amount'],
                'date' => $payment['added_at'],
            ],
        ]);
    }
}
```

### 3. `tests/Feature/Services/Daftra/InvoiceServiceTest.php`

Rewrite the `creates a payment against a Daftra invoice` test
(lines 121-135) to assert the new endpoint, the `InvoicePayment` wrapper, and
`invoice_id` as part of the payload:

```php
it('creates a payment against a Daftra invoice', function () {
    $this->mockClient->shouldReceive('post')
        ->with('/api2/invoice_payments', [
            'InvoicePayment' => [
                'invoice_id' => 555,
                'payment_method' => 1,
                'amount' => 100.0,
                'date' => '2024-01-01',
            ],
        ])
        ->once();

    $this->service->createPayment([
        'InvoicePayment' => [
            'invoice_id' => 555,
            'payment_method' => 1,
            'amount' => 100.0,
            'date' => '2024-01-01',
        ],
    ]);
});
```

### 4. `tests/Feature/Services/SyncOrderTest.php`

Scan for any `createPayment(...)` mock expectations that use the old
two-argument form and update them to the new single-argument wrapped shape to
keep `SyncOrder` tests green.

---

## Files to Modify

1. `app/Services/Daftra/InvoiceService.php` — rewrite `createPayment`.
2. `app/Services/SyncOrder.php` — update caller in `syncPayment()`.
3. `tests/Feature/Services/Daftra/InvoiceServiceTest.php` — update the
   `createPayment` assertion.
4. `tests/Feature/Services/SyncOrderTest.php` — align any affected
   `createPayment` mock expectations.

## Files NOT Modified

- `spec/010-refactor-invoice-service.md` — historical record of the prior
  refactor; its reference to the old signature is left intact.

---

## TODO List

- [x] Update `InvoiceService::createPayment` to `POST /api2/invoice_payments`
  with a single `$data` argument.
- [x] Update `SyncOrder::syncPayment` to pass `invoice_id` inside the
  `InvoicePayment` wrapper.
- [x] Update the payment assertion in `InvoiceServiceTest`.
- [x] Update any affected `createPayment` expectations in `SyncOrderTest`.
- [x] `vendor/bin/pint --dirty --format agent`
- [x] `php artisan test --compact --filter="InvoiceService|SyncOrder"`

---

## Out of Scope

- No handling of the Daftra response body — `createPayment` remains
  fire-and-forget, matching the existing behaviour and the note in
  `spec/010-refactor-invoice-service.md` (line 217).
- No error-handling / custom exception on non-2xx responses (can be added
  later if payment failures need surfacing).
- No changes to optional Daftra fields (`transaction_id`, `treasury_id`,
  `currency_code`, etc.) — add them to the caller's payload as needed.
