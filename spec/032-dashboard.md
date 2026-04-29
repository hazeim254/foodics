# 032 — Dashboard

## Overview

Build the authenticated dashboard shown at the existing root route (`/`, named `dashboard`). The dashboard should replace the current placeholder with operational statistics for invoice and product syncs, plus a compact summary of default Daftra settings.

The project already has `resources/views/dashboard.blade.php` and `DashboardController`; `resources/views/welcome.blade.php` is the unused Laravel starter view and should be removed as part of implementation after confirming no route references it.

## Context

- `routes/web.php` already serves the authenticated root route through `DashboardController`:
  - `GET /` → `DashboardController` → `dashboard`
- `resources/views/dashboard.blade.php` exists, but currently only shows a welcome placeholder.
- `resources/views/welcome.blade.php` still contains the default Laravel starter page and should not remain as the dashboard source.
- `Invoice` uses `InvoiceSyncStatus` with `synced`, `pending`, and `failed` statuses.
- `Product` uses `ProductSyncStatus` with `synced`, `pending`, and `failed` statuses.
- User defaults are stored through `HasSettings` using:
  - `SettingKey::DaftraDefaultClientId`
  - `SettingKey::DaftraDefaultBranchId`

## Decisions

| Concern | Decision |
|---------|----------|
| Route | Keep the current authenticated `/` route and `dashboard` route name unchanged |
| View file | Use `resources/views/dashboard.blade.php` as the canonical dashboard view |
| Starter view | Delete `resources/views/welcome.blade.php` only after confirming it is unreferenced |
| Data scope | All statistics must be scoped to the authenticated user |
| Query style | Use the user relationships (`$user->invoices()`, `$user->products()`) rather than raw queries |
| Settings display | Show stored setting values, with human-friendly fallbacks when unset |
| Charts | Do not implement charting until explicitly approved |

## Requirements

### 1. Controller — `DashboardController`

Update `DashboardController` to collect and pass dashboard data to the view.

The controller should provide:

- Invoice counts by sync status:
  - Synced invoices
  - Failed invoice syncs
  - Pending invoice syncs
- Product counts by sync status:
  - Synced products
  - Failed product syncs
  - Pending product syncs
- Totals:
  - Total invoices
  - Total products
- Sync success rates:
  - Invoice sync success rate
  - Product sync success rate
- Sync over time data:
  - Daily synced/failed invoice counts
  - Daily synced/failed product counts
- Default settings:
  - Daftra Default Client ID
  - Daftra Default Branch ID
Recommended view data shape:

```php
[
    'invoiceStats' => [
        'total' => 0,
        'synced' => 0,
        'failed' => 0,
        'pending' => 0,
        'success_rate' => 0,
    ],
    'productStats' => [
        'total' => 0,
        'synced' => 0,
        'failed' => 0,
        'pending' => 0,
        'success_rate' => 0,
    ],
    'syncOverTime' => [
        'labels' => [],
        'invoices' => [
            'synced' => [],
            'failed' => [],
        ],
        'products' => [
            'synced' => [],
            'failed' => [],
        ],
    ],
    'defaultSettings' => [
        'client_id' => null,
        'branch_id' => null,
    ],
]
```

Implementation notes:

- Use `auth()->user()` once and reuse it.
- Count via Eloquent relationship queries, scoped to the user.
- Use enum values/cases rather than hard-coded status strings where practical.
- Treat a missing branch ID as Daftra's default branch (`1`) in the UI, because the settings controller stores `null` for branch `1`.
- Calculate success rate as `synced / total * 100`; when total is `0`, show `0%`.
- Build the initial sync over time chart for the last 7 days unless implementation discovers an existing app pattern that suggests another range.
- Group daily counts by `created_at`.

### 2. View — `resources/views/dashboard.blade.php`

Replace the current placeholder with a production dashboard using the existing `layouts.app` layout.

Required sections:

#### Header

- Page title: `Dashboard`
- Short subtitle explaining this page summarizes Foodics to Daftra sync health.

#### Invoice Sync Summary

Display three statistic cards:

| Card | Source |
|------|--------|
| Total invoices | `$invoiceStats['total']` |
| Synced invoices | `$invoiceStats['synced']` |
| Failed invoice syncs | `$invoiceStats['failed']` |
| Pending invoice syncs | `$invoiceStats['pending']` |

Also show the invoice sync success rate as a percentage.

#### Product Sync Summary

Display three statistic cards:

| Card | Source |
|------|--------|
| Total products | `$productStats['total']` |
| Synced products | `$productStats['synced']` |
| Failed product syncs | `$productStats['failed']` |
| Pending product syncs | `$productStats['pending']` |

Also show the product sync success rate as a percentage.

#### Sync Over Time Chart

Add a simple sync over time chart showing synced and failed counts over the last 7 days.

The chart should include:

- Invoice synced count per day
- Invoice failed count per day
- Product synced count per day
- Product failed count per day

Implementation can use lightweight Blade/CSS/SVG if sufficient. Do not add a new frontend chart dependency without approval.

#### Default Settings

Display a settings summary card:

| Setting | Display |
|---------|---------|
| Default Client | The configured Daftra default client ID, or `Not configured` |
| Default Branch | The configured branch ID, or `Default branch (1)` |

Add a link/button to `route('settings')` so users can update the defaults.

### 3. Design Direction

Follow the existing app design language from `invoices.blade.php`, `products.blade.php`, and `settings.blade.php`:

- App layout: `@extends('layouts.app')`
- Max width: `max-w-7xl mx-auto`
- Cards: white/dark backgrounds, rounded corners, subtle borders, shadow-sm
- Primary action color: Daftra blue `#4A90D9`
- Status accents:
  - Synced: green
  - Pending: amber
  - Failed: red
- All user-facing text must be wrapped with `__()`.
- Add Arabic translations for any new strings when implementation starts. If `lang/ar.json` does not exist yet, create it then.

Suggested layout:

```text
Dashboard
Foodics to Daftra sync health at a glance.

┌────────────────────────────────────────────────────────────┐
│ Invoice Sync                                               │
│ [Total] [Synced] [Failed] [Pending] [Success Rate]         │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│ Product Sync                                               │
│ [Total] [Synced] [Failed] [Pending] [Success Rate]         │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│ Sync Over Time                                             │
│ [7-day synced/failed invoice and product chart]            │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│ Default Settings                                           │
│ Default Client: 12345                                      │
│ Default Branch: Default branch (1)             [Settings]  │
└────────────────────────────────────────────────────────────┘
```

### 4. View Rename / Cleanup

The intended final state is:

- `resources/views/dashboard.blade.php` contains the dashboard UI.
- `resources/views/welcome.blade.php` is removed if it has no references.
- The route remains `GET /` named `dashboard`.

Before deleting `welcome.blade.php`, search for references to:

- `view('welcome')`
- `view("welcome")`
- `welcome.blade.php`

If any references exist, update them to the dashboard route/view as appropriate.

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| User has no invoices | All invoice counts show `0` |
| User has no products | All product counts show `0` |
| User has failed and pending records | Failed and pending cards surface those counts clearly |
| User has no default client | Display `Not configured` |
| User has no branch setting | Display `Default branch (1)` |
| User has no records | Success rates show `0%` without division errors |
| Another user has records | Counts must not include them |
| Guest visits `/` | Existing auth middleware redirects to login |

## Files to Modify

1. `app/Http/Controllers/DashboardController.php` — collect dashboard statistics and settings.
2. `resources/views/dashboard.blade.php` — replace placeholder with dashboard UI.
3. `resources/views/welcome.blade.php` — remove only if unreferenced.
4. `lang/ar.json` — add Arabic translations for new user-facing strings during implementation.

## Tests

Create `tests/Feature/DashboardTest.php` using Pest.

Required test coverage:

- Guest cannot access the dashboard and is redirected to login.
- Authenticated user can access `/` and sees the dashboard view.
- Dashboard shows invoice counts for synced, failed, and pending statuses.
- Dashboard shows product counts for synced, failed, and pending statuses.
- Dashboard shows total invoice and product counts.
- Dashboard shows invoice and product success rates.
- Dashboard shows sync over time chart data for the authenticated user's records.
- Dashboard only counts records belonging to the authenticated user.
- Dashboard shows configured default client and default branch.
- Dashboard shows fallback text when default client and branch are not configured.
- Dashboard includes a link to the settings page.

Run:

```bash
php artisan test --compact tests/Feature/DashboardTest.php
vendor/bin/pint --dirty --format agent
```

## Tasks

- [ ] Confirm `welcome.blade.php` has no active references.
- [ ] Update `DashboardController` to pass invoice stats, product stats, and default settings.
- [ ] Add total counts and success rate calculations.
- [ ] Add 7-day sync over time data.
- [ ] Replace the dashboard placeholder UI.
- [ ] Remove `resources/views/welcome.blade.php` if unreferenced.
- [ ] Add Arabic translations for new user-facing strings.
- [ ] Create `tests/Feature/DashboardTest.php`.
- [ ] Run `php artisan test --compact tests/Feature/DashboardTest.php`.
- [ ] Run `vendor/bin/pint --dirty --format agent`.

## Out of Scope

- No new dashboard routes or APIs.
- No new database tables or columns.
- No changes to invoice/product sync jobs.
- No real-time polling on the dashboard.
- No settings editing inside the dashboard; users should continue to update settings from the settings page.
- No recent failed syncs panel.
- No standalone connection health card beyond the settings completeness checklist.
- No quick actions section.
- No last sync activity section.
