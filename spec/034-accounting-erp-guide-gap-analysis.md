# 034 - Foodics Accounting/ERP Guide Gap Analysis

## Overview

This spec captures the next work after the completed sales-sync specs and aligns it with the Foodics Accounting/ERP Integration guide.

The existing specs are considered fulfilled. Any old unchecked task lists in earlier specs were stale documentation state, not open implementation work.

## Current Baseline

The application already has the core sales-sync bridge:

- Dual Foodics/Daftra OAuth and per-user provider tokens.
- Foodics `/whoami` lookup during login for business identity mapping.
- Batch order fetch using guide-style sales includes, `sort=reference`, `filter[status]=4,5`, and cursor progression by Foodics reference.
- Single-order fetch for webhook and retry paths.
- Daftra invoice creation for completed orders.
- Daftra credit-note creation for returned orders linked to the original invoice.
- Product, modifier option, combo child product, charge, tax, discount, payment method, client, and default client/branch handling.
- Webhook processing, retry flows, invoices/products pages, settings, dashboard, and client search.

## Guide Coverage

| Guide area | Current state | Next action |
| --- | --- | --- |
| OAuth code grant | Implemented for the current sales-sync scopes | Add disconnect/revoke support before expanding scope surface |
| Recommended scopes | Sales scopes are present; inventory, ingredients, house-account, and revoke scopes are not all present | Add `tokens.limited.revoke` now; defer inventory and house-account scopes until those modules are scheduled |
| `/whoami` | Implemented during Foodics callback | No immediate action |
| `/settings` | Not yet represented in the app | Fetch and persist `business_currency` and `business_timezone` |
| Sales order fetch | Implemented for statuses `4,5`, broad includes, 50-order pages, and reference cursor | Consider sending `filter[reference_after]=0` on first sync for exact guide parity |
| Products/options/charges/taxes/discounts | Implemented for normal product lines, modifier options, charges, line taxes, order discounts, and tax matching by name/rate | Add reconciliation coverage for exact amount behavior |
| Combos | Combo child products are synced; combo product options and combo wrapper discounts remain intentionally deferred | Decide through reconciliation whether to model combo options and wrapper discounts |
| Returned orders | Implemented as Daftra credit notes | Add returned-payment handling once Daftra behavior is confirmed |
| Tips and rounding | Not represented as explicit Daftra lines or adjustments | Add sales reconciliation and then implement the chosen Daftra representation |
| Branch | Foodics branch is included; Daftra branch is controlled by user default branch | Add branch mapping if per-Foodics-branch accounting is required |
| Deleted objects, nullable fields, UTC timing | Handled in places, but not as a documented cross-cutting policy | Define policy after Foodics `/settings` is stored |
| Master data sync | Products exist; taxes/payment methods/clients are resolved on demand | Defer full master-data sync until ERP expansion is approved |
| Inventory sync | Not present | Out of scope for sales-first phase |
| House accounts | Not present | Out of scope for sales-first phase |

## Product Decision

Continue with a sales-first hardening phase before expanding into full ERP inventory and house-account sync.

Rationale:

- The current product purpose is a Foodics-to-Daftra sales bridge for operational troubleshooting and retry.
- The most likely accounting risk is not missing inventory data; it is a subtle mismatch between Foodics order totals and Daftra invoice/credit-note totals.
- Inventory and house accounts require broader scopes, more domain mapping, and new operator workflows. They should be separate approved modules, not incremental additions to the sales-sync path.

## Next Implementation Specs

### 1. Sales Reconciliation and Amount Accuracy

Create a focused implementation spec for an order-level reconciliation layer.

Coverage should include:

- `subtotal_price`, `total_price`, `discount_amount`, and `rounding_amount`.
- Product taxes from `products.taxes.pivot.amount`.
- Product option taxes from `products.options.taxes.pivot.amount`.
- Charge amounts and charge taxes.
- Combo product taxes and currently deferred combo product options.
- Combo wrapper discounts when Foodics does not distribute them onto child product lines.
- Payment amounts, multi-payment orders, and payment tips.
- Returned-order credit-note totals and returned-order payments.
- Inclusive/exclusive tax behavior once Foodics settings are available.

Acceptance criteria:

- A test fixture can describe the expected Foodics accounting components for a completed order and a returned order.
- The system can identify differences between Foodics expected totals and the Daftra payload/result.
- Differences are visible enough for operators to diagnose without manually recomputing the order.
- The reconciliation tolerates normal decimal rounding but flags material drift.

### 2. Foodics Settings Persistence

Create a Foodics settings spec before implementing tax inclusivity or timezone-sensitive reconciliation.

Implementation direction:

- Add a Foodics service method for `GET /v5/settings`.
- Persist at least `business_currency` and `business_timezone` in user-scoped metadata or settings.
- Refresh settings during Foodics OAuth callback and provide a command or job path to refresh later.
- Use `business_timezone` as the source of truth for interpreting Foodics business dates and UTC timestamps.
- Use currency and tax settings as inputs to reconciliation and future UI display.

Acceptance criteria:

- A user has stored Foodics business currency and timezone after connecting Foodics.
- Missing or nullable settings are handled explicitly.
- Tests cover successful fetch, failed fetch, and persistence.

### 3. Token Revoke and Disconnect Flow

Create a disconnect spec before requesting broader long-lived scopes.

Implementation direction:

- Add the `tokens.limited.revoke` Foodics scope.
- Add a disconnect action that calls Foodics token revoke for the active token.
- Remove or invalidate local Foodics provider tokens after successful revoke.
- Make the UI state clear when Foodics is disconnected.
- Decide whether Daftra disconnect should be symmetrical or separate.

Acceptance criteria:

- The app can revoke a Foodics token and prevent future Foodics API calls with that connection.
- Failed revoke attempts are surfaced without deleting the local token prematurely.
- Tests cover successful revoke, revoke failure, and already-disconnected users.

### 4. ERP Expansion Gate

Do not start inventory or house-account implementation until a separate product decision approves full ERP scope.

If approved, plan the work as separate specs:

- Accounting master-data sync: branches, warehouses, suppliers, taxes, payment methods, customers, and deleted-object policy.
- Inventory transactions: purchasing, return to supplier, inventory counts, transfers, adjustments, waste, consumption, production, and quantity/cost effects.
- House accounts: debit and credit payments.
- Operator UI: mapping, retry, diagnostics, and backfill tools for those new domains.

## Recommended Order

1. Sales reconciliation and amount accuracy.
2. Foodics settings persistence.
3. Token revoke and disconnect.
4. Optional branch/payment/tax mapping UX improvements discovered by reconciliation.
5. ERP expansion only after explicit approval.

## Out of Scope

- Implementing inventory sync in the current sales-first phase.
- Implementing house-account sync in the current sales-first phase.
- Adding new Foodics scopes that force reauthorization before the related module is scheduled, except `tokens.limited.revoke`.
- Changing existing completed sales-sync behavior without reconciliation evidence.
