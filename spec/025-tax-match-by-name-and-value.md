# 025 — Tax lookup must match on both name and value

## Overview

Fix `Daftra\TaxService::getTax()` so it only considers a Daftra tax a match when **both** the `name` and the `value` (rate) match the Foodics tax. Today the method filters by `name` only and blindly returns `$rows[0]`, which picks the wrong tax whenever a Daftra account has multiple taxes sharing a name (e.g. `VAT 5%` vs `VAT 15%`, or staggered VAT regimes during a rate change).

When no Daftra tax row matches on **both** `name` and `value`, `getTax()` returns `null` and `resolveTaxId()` falls back to `createTax()` — i.e. we create a new Daftra tax. That fallback path already exists; this spec simply ensures it's reached correctly (today it isn't, because the wrong row gets returned as a match).

In all resolution paths — cache hit, match found via `getTax()`, or freshly created via `createTax()` — the Foodics-to-Daftra tax id pairing is persisted to `App\Models\EntityMapping` (type = `'tax'`, scoped to the current `user_id`). This spec preserves that persistence behaviour; we're only correcting `getTax()`'s match logic, and `resolveTaxId()` (which owns the `EntityMapping` read/write) is untouched.

## Context

- Current behaviour in `app/Services/Daftra/TaxService.php::getTax()`:
  1. `GET /api2/taxes.json?filter[name]=<foodics tax name>`.
  2. If `data` is empty → return `null` (caller creates a new tax via `createTax()`).
  3. Otherwise → return the Daftra id of `data[0]` regardless of its `value`.
- `resolveTaxId()` already does the right thing on a `null` from `getTax()`: it calls `createTax()` and caches the new id in `entity_mappings`. So the only fix needed is making `getTax()` return `null` when no row has a value match.
- This is called from `TaxService::resolveTaxId()` on cache miss (no `EntityMapping` row). The cache then stores whichever Daftra id was picked, so the bug becomes **sticky** for that (user, Foodics tax id) pair.
- The Foodics tax object we already pass in carries `rate` (e.g. `5`, `15`). In `createTax()` / `buildCreatePayload()` we already map this to Daftra's `value` field with `(float)`.
- Daftra's tax list response shape (per existing stub usage): `{ data: [ { Tax: { id, name, value, ... } }, ... ] }`. Each row wraps a `Tax` object.
- Daftra's list filter supports `filter[name]`; from inspection of other services in this codebase and Daftra's general query style, a `filter[value]` is plausible but **not verified**. To stay safe we keep the server-side filter on `name` only and apply the value match on the client side.
- A real Daftra account's tax catalogue is tiny (typically < 10 distinct tax definitions, worst case a few dozen). Paginating is overkill; a single request with a generous `limit` is simpler and sufficient.

## Decisions

| Concern | Decision |
|---------|----------|
| Match predicate | A row is a match iff `row.Tax.name === foodics.name` **and** `(float) row.Tax.value === (float) foodics.rate`. Both comparisons done after casting to avoid string-vs-float mismatches (`"5"` vs `5.0`). Name comparison is exact (case-sensitive) — Daftra preserves casing on create. |
| Server-side filter | Keep `filter[name]` only. Applying a second filter server-side risks coupling to unverified Daftra query syntax. We filter the returned rows in PHP. |
| Multiple results | Walk rows in response order; return the first row whose `value` also matches. If none match, return `null` (→ caller creates a new tax via `createTax()`). |
| Pagination | **No pagination.** Send `limit=100` on the single list request. Tax catalogues don't grow large enough for this to be a concern, and pagination adds complexity (state, ceilings, logging) for no practical benefit. If a real account ever bumps against the ceiling we'll hear about it through the "created a duplicate tax" symptom and can revisit. |
| `limit` value | `100`. Round number, comfortably above any realistic tax catalogue. Passed as a top-level query parameter alongside `filter[name]`. |
| Name-missing edge case | If `foodics.name` is empty/null, skip the lookup entirely and return `null` (go straight to create). Avoids a list request that Daftra may reject or that would return unfiltered rows. |
| Rate-missing edge case | If `foodics.rate` is null, treat it as `0.0` for matching purposes, same as `buildCreatePayload()` already does when constructing a create payload. |
| Float tolerance | Use strict equality after `(float)` cast. Daftra stores `value` as a number; Foodics rates are integers or exact decimals in the API. If a future case shows tiny drift (e.g. `5.0` vs `5.00001`) we can introduce `abs($a - $b) < 1e-6`, but don't pre-optimize. |
| Creation payload | No change — `createTax()` / `buildCreatePayload()` already send `name` and `value` correctly. |
| `EntityMapping` persistence | No change. `resolveTaxId()` already calls `persistTax()` after both the `getTax()`-match branch and the `createTax()` branch, writing a `type = 'tax'` row keyed by `(user_id, foodics_id)` with `daftra_id` and `metadata = { name, rate }`. This spec does not touch `resolveTaxId()`, `persistTax()`, or the `entity_mappings` schema. Subsequent resolutions for the same Foodics tax id hit the cache and skip Daftra entirely. |
| Existing `EntityMapping` rows that cached the wrong id | Out of scope for this spec. A one-off cleanup job or a migration to drop `ofType('tax')` rows could be done in a follow-up; that is a data-fix concern, not a code-path concern. |
| New custom exception | None. Existing `\RuntimeException` throws for HTTP / shape failures stay as they are (they're paths for Daftra API problems, not tax-match logic). Consider consolidating under a custom exception later; keep scope tight here. |

## Files to modify

### 1. `app/Services/Daftra/TaxService.php`

Rework `getTax(array $foodicsTax): ?int` along these lines:

```php
public function getTax(array $foodicsTax): ?int
{
    $taxName = (string) ($foodicsTax['name'] ?? '');
    if ($taxName === '') {
        return null;
    }

    $taxValue = (float) ($foodicsTax['rate'] ?? 0);

    $listResponse = $this->daftraClient->get('/api2/taxes.json', [
        'filter' => ['name' => $taxName],
        'limit' => 100,
    ]);

    if (! $listResponse->successful()) {
        throw new \RuntimeException(
            'Daftra tax list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
        );
    }

    $rows = $listResponse->json('data') ?? [];
    if ($rows === []) {
        return null;
    }

    foreach ($rows as $row) {
        $rowName = (string) ($row['Tax']['name'] ?? '');
        $rowValue = (float) ($row['Tax']['value'] ?? 0);

        if ($rowName === $taxName && $rowValue === $taxValue) {
            return $this->daftraTaxIdFromListRow($row);
        }
    }

    return null;
}
```

No `Log` import needed; no pagination loop; no ceiling. The existing `resolveTaxId()` handles the `null` return by calling `createTax()`.

No other method needs to change.

## Tests

### `tests/Feature/Services/Daftra/TaxServiceTest.php`

Add / update:

- **`it('returns null when Daftra returns no rows for the name')`** — list response with `data = []`; assert `getTax()` returns `null`.
- **`it('returns the Daftra id when both name and value match')`** — list response with one row whose `Tax.name` and `Tax.value` match; assert the row id is returned.
- **`it('skips rows whose value does not match the Foodics rate')`** — list response with two rows sharing the same name, different values; the Foodics tax has `rate = 15` and only the second row has `value = 15`; assert the **second** row's id is returned (not `rows[0]`).
- **`it('returns null when Daftra has the name but no matching value')`** — list response with rows all sharing `Tax.name = 'VAT'` but with values `5`, `10`, `12`; Foodics tax rate is `15`; assert `getTax()` returns `null`.
- **`it('creates a new Daftra tax via resolveTaxId when no row matches on both name and value')`** — list response returns rows with matching name but non-matching values; stub the `POST /api2/taxes.json` create response; assert `resolveTaxId()` returns the newly created id **and** an `EntityMapping` row exists with `type = 'tax'`, the correct `user_id`, `foodics_id`, and the newly created `daftra_id`.
- **`it('persists an EntityMapping when resolveTaxId finds a name+value match')`** — list response with a single matching row; assert `resolveTaxId()` returns the match's id **and** an `EntityMapping` row exists with `type = 'tax'`, correct `user_id`, `foodics_id`, `daftra_id`, and `metadata` containing the Foodics `name` and `rate`.
- **`it('hits the EntityMapping cache on subsequent resolutions')`** — pre-seed an `EntityMapping` row for the given `(user_id, foodics_id)`; assert `resolveTaxId()` returns the cached `daftra_id` and that **no** HTTP calls were made to Daftra.
- **`it('returns null immediately when Foodics tax has no name')`** — Foodics tax with `name = null`; assert no HTTP call is made and `null` is returned.
- **`it('treats null Foodics rate as 0.0 for matching')`** — Foodics tax `rate = null`; Daftra row with `value = 0`; assert the row id is returned.
- **`it('casts value comparison through float')`** — Daftra row with `Tax.value = "5"` (string from API); Foodics `rate = 5`; assert the row id is returned.
- **`it('sends limit=100 on the tax list request')`** — assert the outgoing request carries `limit=100` (use `Http::assertSent` or the existing test harness pattern for the Daftra client).

### `tests/Feature/Services/SyncOrderTaxTest.php`

No new tests required; the change is transparent to `SyncOrder`. Run the file after the fix to confirm no regressions.

## Edge cases

| Scenario | Behaviour |
|----------|-----------|
| Two Daftra taxes with identical name **and** value | Pick the first (response order). Ambiguity in Daftra data is not our problem to resolve; the cache pins whatever we picked. |
| Daftra `Tax.value` as string | Handled by `(float)` cast. |
| Daftra list endpoint ignores `filter[name]` | The client-side name check (`$rowName === $taxName`) still filters correctly; we just walk up to 100 rows in PHP. |
| User's Daftra account has no taxes | Response returns `data = []` → `null` → caller creates. |
| User's Daftra account has > 100 taxes sharing the matching name | Extremely unlikely. Worst case: we miss the match and create a duplicate tax. Visible and fixable; not worth paginating for. |
| Intermittent HTTP 5xx on the list call | `throw` on any non-successful response, same as today. `resolveTaxId()`'s caller surfaces the error; sync fails loudly. |

## Out of scope

- Cleaning up `entity_mappings` rows that were cached against the wrong Daftra tax id (data migration).
- Changing `createTax()` / `buildCreatePayload()`.
- Handling tax `included` (inclusive vs exclusive) — separate concern, belongs with the tax-mode spec.
- Any changes to option / charge / combo tax handling in `SyncOrder`.

## Tasks

- [x] Rework `app/Services/Daftra/TaxService.php::getTax()` to filter on name server-side (with `limit=100`) and match on both name and value client-side.
- [x] Add the listed Pest tests in `tests/Feature/Services/Daftra/TaxServiceTest.php`.
- [x] Run `php artisan test --compact tests/Feature/Services/Daftra/TaxServiceTest.php tests/Feature/Services/SyncOrderTaxTest.php`.
- [x] Run `vendor/bin/pint --dirty --format agent`.

## References

- `app/Services/Daftra/TaxService.php`
- `spec/002-handle-taxes.md` (original spec — this spec corrects a behaviour decision from there).
- Foodics tax object shape: `{ id, name, rate, pivot: { amount, rate } }` (see `json-stubs/foodics/get-order.json`).
- Daftra taxes API: `GET /api2/taxes.json`, `POST /api2/taxes.json`.
