# 033 — Client Search Dropdown in Settings

## Overview

Replace the plain text input for **Daftra Default Client ID** on the Settings page with an AJAX-powered searchable dropdown. Users search Daftra clients by name, select one from the results, and the client ID is submitted with the form. If a default client is already saved, its details are shown on page load.

## Context

- The Settings page (`resources/views/settings.blade.php`) currently has a text input for `daftra_default_client_id` (spec 020).
- `DaftraApiClient` handles all Daftra HTTP calls with auth, token refresh, and branch ID injection (spec 004). Its constructor requires a `User` instance, resolved from the container as the authenticated user.
- `ClientService` depends on `DaftraApiClient` via constructor injection (`__construct(protected DaftraApiClient $daftraClient)`). Both are resolved from the container via `app()` — `DaftraApiClient` auto-receives the authenticated user, and `ClientService` auto-receives the configured `DaftraApiClient`.
- **Users must be connected to Daftra to log in**, so `$user->hasDaftraConnection()` is always `true` for any authenticated user. No "no connection" fallback is needed.
- The app uses **Alpine.js** (loaded via `resources/js/app.js`) and native `fetch()` for AJAX (see `products.blade.php`, `invoices.blade.php`).
- The Daftra auto-suggest endpoint `GET /v2/api/entity/client/filter-auto-suggest?filter[business_name][like]={query}` returns an array of client objects with keys: `id`, `name`, `avatar`.

### Decisions

| Concern | Decision |
|---------|----------|
| Search mechanism | Debounced (300 ms) client-side fetch to a backend endpoint; minimum 2 characters to trigger |
| Backend proxy | New controller method resolves `ClientService` from container (which auto-resolves `DaftraApiClient` with the authenticated user). Returns JSON. Avoids exposing Daftra credentials to the browser. |
| Initial load (saved client) | Server-side: controller resolves saved client ID to `{id, name, avatar}` via Daftra list endpoint, passes to view |
| UI framework | Alpine.js (already in the project) for the dropdown component |
| Dropdown library | None — custom Alpine.js component matching existing design tokens |
| Clear / re-search | Selected state shows avatar + name + clear button; clearing returns to search mode |

---

## Route

```
GET /settings/search-clients → SettingController@searchClients → named('settings.search-clients')
```

- Must be within the `auth` middleware group.
- Returns JSON: `{ data: [{ id, name, avatar }, …] }`.
- Existing `GET /settings` and `POST /settings` routes are unchanged.

---

## Requirements

### 1. `ClientService` — new methods

```php
public function searchClients(string $query): array
{
    $response = $this->daftraClient->get(
        '/v2/api/entity/client/filter-auto-suggest',
        ['filter' => ['business_name' => ['like' => $query]]],
    );

    if (! $response->successful()) {
        throw new \RuntimeException(
            'Daftra client search failed: HTTP ' . $response->status()
        );
    }

    return $response->json('data') ?? [];
}

public function findClientById(int $id): ?array
{
    $response = $this->daftraClient->get(
        '/v2/api/entity/client/list',
        ['filter' => ['id' => $id]],
    );

    if (! $response->successful()) {
        return null;
    }

    $rows = $response->json('data') ?? [];

    return $rows[0] ?? null;
}
```

- `searchClients()` proxies to the auto-suggest endpoint. Returns the raw array from Daftra.
- `findClientById()` uses the existing list endpoint with an ID filter. Returns a single client array or `null`. Used only for initial page-load resolution.

### 2. `SettingController` — modify `index`, add `searchClients`

```php
public function index()
{
    $user = auth()->user();
    $daftraDefaultClient = null;

    $branches = app(DaftraApiClient::class)->tryGetBranches();

    $clientId = $user->setting(SettingKey::DaftraDefaultClientId);
    if ($clientId !== null && $clientId !== '') {
        $daftraDefaultClient = app(ClientService::class)->findClientById((int) $clientId);
    }

    return view('settings', [
        'daftraDefaultClientId'  => $clientId,
        'daftraDefaultClient'    => $daftraDefaultClient,
        'daftraDefaultBranchId'  => $user->setting(SettingKey::DaftraDefaultBranchId),
        'branches'               => $branches,
    ]);
}

public function searchClients(Request $request): JsonResponse
{
    $request->validate(['query' => 'required|string|min:2|max:255']);

    $results = app(ClientService::class)
        ->searchClients($request->input('query'));

    return response()->json(['data' => $results]);
}
```

- `index()` now also passes `daftraDefaultClient` (array or `null`) to the view. No `hasDaftraConnection()` guard needed — all authenticated users have Daftra connected.
- `searchClients()` validates the query, resolves `ClientService` from the container (which receives the authenticated user via `DaftraApiClient`), returns JSON.

### 3. Route — `routes/web.php`

Add inside the `auth` middleware group:

```php
Route::get('/settings/search-clients', [SettingController::class, 'searchClients'])
    ->name('settings.search-clients');
```

### 4. Blade View — `resources/views/settings.blade.php`

Replace the existing `daftra_default_client_id` text input with an Alpine.js searchable dropdown component.

#### Component state

```
x-data="{
    selectedId: '{{ $daftraDefaultClientId ?? '' }}',
    selectedName: '{{ $daftraDefaultClient['name'] ?? '' }}',
    selectedAvatar: '{{ $daftraDefaultClient['avatar'] ?? '' }}',
    query: '',
    results: [],
    loading: false,
    open: false,
}"
```

#### Behaviour

| Interaction | Behaviour |
|-------------|-----------|
| **Page load (saved client)** | If `selectedId` is non-empty, show selected state: avatar image + client name + ✕ clear button. Hidden input holds the ID. |
| **Page load (no saved client)** | Show search input with placeholder "Search for a client…" |
| **User types in search** | After 300 ms debounce and ≥ 2 characters, `fetch GET /settings/search-clients?query=…`, set `results`. Show loading spinner during fetch. |
| **Fewer than 2 characters** | Show hint "Type at least 2 characters to search…", no API call. |
| **Results returned** | Dropdown appears below input. Each row: avatar (32×32 rounded-full) + name. |
| **No results** | Dropdown shows "No clients found." |
| **Click a result** | Set `selectedId`, `selectedName`, `selectedAvatar`; close dropdown; show selected state. |
| **Click clear (✕)** | Reset `selectedId` to `''`, return to search mode. |
| **Click outside dropdown** | Close dropdown (`@click.away`). |

#### Layout

```
┌─────────────────────────────────────────────────┐
│ Default Client                                   │
│                                                  │
│ ┌─ selected state ─────────────────────────────┐ │
│ │ [avatar] Client Name                     [✕] │ │
│ └──────────────────────────────────────────────┘ │
│                                                  │
│ ── or (search mode) ──                           │
│                                                  │
│ ┌──────────────────────────────────────────────┐ │
│ │ Search for a client…                         │ │
│ └──────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────┐│
│  │ [avatar] Result 1                            ││
│  │ [avatar] Result 2                            ││
│  │ [avatar] Result 3                            ││
│  └──────────────────────────────────────────────┘│
│                                                  │
│ Client used when a Foodics order has no          │
│ customer (walk-in).                              │
└─────────────────────────────────────────────────┘
```

#### Hidden input

```html
<input type="hidden" name="daftra_default_client_id" :value="selectedId">
```

This replaces the old `<input type="text">` so the form POST continues to work unchanged.

#### Styling

Follow existing design tokens (spec 020):
- Search input: same as the current text input class.
- Dropdown panel: `absolute z-50 w-full mt-1 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg shadow-lg max-h-60 overflow-y-auto`.
- Result row: `flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-[#F5F5F3] dark:hover:bg-[#262625]`.
- Selected state: `flex items-center gap-3 w-full rounded-lg border … px-4 py-2.5`.
- Loading: small spinner SVG or "Searching…" text.
- Avatar: `w-8 h-8 rounded-full object-cover` (selected) / `w-6 h-6 rounded-full object-cover` (results).

---

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Saved client ID no longer exists in Daftra | Show ID as plain text with a "Client not found" warning, allow re-search |
| API returns error during search | Show "Search failed. Try again." in dropdown |
| User submits form without selecting (query text only) | Hidden input is empty or still holds previous `selectedId` — no accidental partial text submission |
| User types very fast | Debounce collapses multiple keystrokes into one API call |
| Empty search results | Dropdown shows "No clients found." |
| User clicks away while loading | Dropdown closes, loading aborted if possible |

---

## Files to Create

_None._

## Files to Modify

### 1. `app/Services/Daftra/ClientService.php`

- Add `searchClients(string $query): array` method.
- Add `findClientById(int $id): ?array` method.

### 2. `app/Http/Controllers/SettingController.php`

- Modify `index()` to resolve saved client ID to client details, pass `daftraDefaultClient` to view.
- Add `searchClients(Request $request): JsonResponse` method.

### 3. `routes/web.php`

- Add `GET /settings/search-clients` route inside `auth` middleware group.

### 4. `resources/views/settings.blade.php`

- Replace the `<input type="text" id="daftra_default_client_id">` with the Alpine.js searchable dropdown component.

---

## Tests

### `tests/Feature/SettingsPageTest.php` (extend existing)

- Authenticated user can search clients via `GET /settings/search-clients?query=acme`.
- Search requires `query` parameter (422 without it).
- Search requires minimum 2 characters (422 with 1 character).
- Guest cannot access search endpoint (redirect to login).
- Saved client details are available in the settings view when a client is configured.
- Settings view renders correctly when no saved client exists.

### `tests/Unit/ClientServiceTest.php` (new or extend existing)

- `searchClients()` returns array from Daftra API response.
- `findClientById()` returns client array when found.
- `findClientById()` returns `null` when not found.

---

## Tasks

- [x] Add `searchClients()` and `findClientById()` to `app/Services/Daftra/ClientService.php`
- [x] Modify `app/Http/Controllers/SettingController.php` (index + searchClients)
- [x] Add route in `routes/web.php`
- [x] Update `resources/views/settings.blade.php` (Alpine.js dropdown)
- [x] Write/update tests
- [x] Run `php artisan test --compact --filter=SettingsPage`
- [x] Run `vendor/bin/pint --dirty --format agent`

---

## Out of Scope

- Pagination of search results (the auto-suggest endpoint returns a reasonable set).
- Client creation from the dropdown (only searching/selecting existing clients).
- Caching of search results.
- Keyboard navigation (arrow keys / enter) in the dropdown — basic click-only is sufficient for v1.
