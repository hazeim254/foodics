# Roadmap

## 1. Webhook Security

Since Foodics does not provide webhook signature verification, secure the endpoint with an alternative approach.

- [ ] **Replace webhook route with signed URL** — Use Laravel's `SignedUrl` route (`/webhooks/foodics/{token}`) so the endpoint is not guessable. Remove the dead `verifySignature()` method.
- [ ] **Log and reject invalid signature calls** — Return 403 with a warning log when the signed URL check fails.

## 2. Webhook Handler Stubs

The `order.updated` and `order.cancelled` handlers currently only log and do nothing.

- [ ] **Implement `order.updated` handler** — Re-fetch the order from Foodics, find the existing local invoice by `foodics_reference`, and re-sync it (update invoice in Daftra or create credit note if status changed to returned).
- [x] **Implement `order.cancelled` handler** — Find the existing local invoice, void/cancel it in Daftra if supported, and update local status to `cancelled`.
- [ ] **Write tests for both handlers** — Cover happy path, missing invoice, and API failure cases.

## 3. Inventory Sync

The Foodics Accounting guide requires syncing inventory data. This is the largest missing feature (~14 test cases in the guide).

- [ ] **Add Foodics inventory API client methods** — Create `Foodics\InventoryService` with methods for fetching inventory items, transactions, counts, and levels using cursor-based pagination.
- [ ] **Create `inventory_items` table migration** — Store synced inventory items with Foodics ID, name, SKU, unit, and Daftra mapping fields.
- [ ] **Create `inventory_transactions` table migration** — Store synced transactions (purchasing, returns, transfers, adjustments, waste, consumption, production) with type, quantities, costs, and status.
- [ ] **Implement purchasing transaction sync** — Map Foodics purchasing transactions to Daftra inventory/purchase records.
- [ ] **Implement return-to-supplier sync** — Handle negative inventory transactions as returns.
- [ ] **Implement transfer in/out sync** — Handle transfers between branches/warehouses (fully received, partially received, rejected).
- [ ] **Implement inventory count sync** — Sync periodic stock counts with positive/negative variance handling.
- [ ] **Implement cost and quantity adjustment sync** — Handle cost adjustments and quantity adjustments.
- [ ] **Implement waste/consumption from orders sync** — Map order-related inventory movements.
- [ ] **Implement production transaction sync** — Handle production and waste-from-production transactions.
- [ ] **Add inventory sync UI page** — List inventory transactions with filters and sync status, similar to invoices page.
- [ ] **Add `inventory:sync` artisan command** — Manual trigger for inventory sync per user.
- [ ] **Write tests for all inventory transaction types** — Cover each of the 14 test cases from the Foodics guide.

## 4. Master Data Sync

The Foodics guide recommends syncing branches, warehouses, suppliers, and customers as accounting master data.

- [x] **Sync branches from Foodics** — Fetch from `/v5/branches` on demand (cursor-based pagination via `Foodics\BranchService`), map to Daftra branches using `entity_mappings` table, with a dedicated `/mappings` UI page. Per-order branch resolution in `SyncOrder` uses the mapping to set Daftra branch context. No local `branches` table needed — data is fetched on demand and stored in session.
- [ ] **Sync warehouses from Foodics** — Fetch from `/warehouses`, store locally, and map. Add `warehouses` table.
- [x] **Sync taxes from Foodics and Daftra** — Fetch from `/v5/taxes` (Foodics) and `/api2/taxes.json` (Daftra) on demand, map via `entity_mappings` table with auto-matching by name/rate and manual override on `/mappings` page. `Foodics\TaxService` and `Daftra\TaxService::listTaxes()` added.
- [ ] **Sync suppliers from Foodics** — Fetch from `/suppliers`, store locally, and map to Daftra suppliers. Add `suppliers` table.
- [ ] **Full customer sync with house accounts** — Sync customer debit/credit payments from `/customers` and house account transactions.
- [ ] **Write tests for each master data sync** — Cover creation, update, and deletion handling (branches and taxes already covered; remaining: warehouses, suppliers, customers).

## 5. Operational Hardening

Infrastructure improvements for reliability and observability.

- [ ] **Add scheduled order sync** — Register a scheduled command in `routes/console.php` to periodically sync orders for all active users (e.g., every 15 minutes).
- [ ] **Add failure notifications** — Alert on critical job failures (webhook processing, sync failures) via log channels or email.
- [ ] **Complete `.env.example`** — Add all Foodics/Daftra OAuth config vars (`DAFTRA_OAUTH_URL`, `DAFTRA_BASE_URL`, `DAFTRA_APP_ID`, `DAFTRA_APP_SECRET`, `DAFTRA_REDIRECT_URI`, `FOODICS_OAUTH_URL`, `FOODICS_BASE_URL`, `FOODICS_CLIENT_ID`, `FOODICS_CLIENT_SECRET`, `FOODICS_REDIRECT_URI`).
- [ ] **Fix `ProviderToken` model** — Convert `$casts` property to `casts()` method per Laravel 12 convention.
- [ ] **Implement credit note payment sync** — Remove the warning log and properly sync payments on returned orders to Daftra credit notes.
