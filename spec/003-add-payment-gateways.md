# 003 - Add Payment Gateway Mapping

## Overview

Map Foodics payment methods to Daftra payment gateways so that invoice payments synced to Daftra use the correct Daftra payment method instead of sending the raw Foodics name string.

## Context

- `SyncOrder::syncPayment()` currently sends `payment_method` as the raw Foodics name (e.g. `"Card"`) with no mapping or caching.
- The `entity_mappings` table and `EntityMapping` model already exist and support polymorphic types via the `type` column (currently used for `tax`).
- Daftra provides APIs to list and create payment gateways.

## Daftra Payment Methods API

| Action   | Endpoint                       | Method | Notes                        |
| -------- | ------------------------------ | ------ | ---------------------------- |
| List all | `/site_payment_gateway/list/1` | GET    | Returns all payment gateways |
| Create   | `/site_payment_gateway`        | POST   | Creates a new gateway        |

### Create Payload Fields

- `payment_gateway` (string) — slug identifier, e.g. `"card"`
- `label` (string) — display name, e.g. `"Card"`
- `manually_added` (int) — set to `1`
- `active` (int) — set to `1`
- `treasury_id` (nullable)
- `branch_id` (int)

## Files to Create

### 1. `app/Services/Daftra/PaymentMethodService.php`

Follows the `TaxService` pattern (`TaxService.php`).

**Methods:**

- `resolvePaymentMethod(array $foodicsPaymentMethod): int`
  1. Check `EntityMapping` where `type = 'payment_method'` and `foodics_id` = Foodics payment method ID → return cached `daftra_id`
  2. Call `getPaymentMethods()` → search the list for a matching label → if found, persist mapping and return Daftra ID
  3. Call `createPaymentMethod()` → create in Daftra, persist mapping, return Daftra ID

- `getPaymentMethods(): array` — GET `/site_payment_gateway/list/1`, returns the full list

- `createPaymentMethod(array $foodicsPaymentMethod): int` — POST `/site_payment_gateway` with:
  - `payment_gateway` = slugified name
  - `label` = Foodics payment method name
  - `manually_added = 1`, `active = 1`
  - Returns the new Daftra ID

- `persistPaymentMethod(int $userId, string $foodicsId, int $daftraId, array $foodicsPaymentMethod): void` — saves to `entity_mappings` with `type = 'payment_method'` and `metadata` containing `{name, code}`

## Files to Modify

### 2. `app/Services/SyncOrder.php`

- Add `PaymentMethodService` to constructor
- Add `array $paymentMethodMap` property (parallel to `$taxMap`)
- In `handle()`, resolve all unique payment methods from `$order['payments']` before building invoice
- Update `syncPayment()` to:
  - Look up each payment's `payment_method.id` in `$paymentMethodMap`
  - Use the resolved Daftra label for the `payment_method` field in the payload sent to `createPayment()`

## Data Flow

```
Foodics Order payments[].payment_method
  → {id: "8df57bde", name: "Card", type: 2, code: "Card"}
      ↓
  Check entity_mappings (type='payment_method', foodics_id='8df57bde')
      ↓ (not found)
  GET /site_payment_gateway/list/1 → search for matching label
      ↓ (not found)
  POST /site_payment_gateway → create "Card" in Daftra → get ID
      ↓
  Persist to entity_mappings (type='payment_method')
      ↓
  Use resolved label in Daftra invoice payment
```

## No New Migrations or Models

The `entity_mappings` table already supports `type = 'payment_method'`. No schema changes needed.

## Tasks

- [x] Create `PaymentMethodService` with `resolvePaymentMethod`, `getPaymentMethods`, `createPaymentMethod`, `persistPaymentMethod`
- [x] Update `SyncOrder` constructor to inject `PaymentMethodService`
- [x] Add payment method resolution logic in `SyncOrder::handle()`
- [x] Update `SyncOrder::syncPayment()` to use mapped payment methods
- [x] Write tests for `PaymentMethodService`
- [x] Update existing `SyncOrder` tests to cover payment method mapping
