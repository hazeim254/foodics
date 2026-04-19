# 020 — Settings Page

## Overview

Build the Settings page UI so users can manage their per-user configuration. The initial (and currently only) setting is the **Daftra Default Client ID**, used as a fallback client when syncing walk-in Foodics orders (spec 013).

## Context

- The data layer is fully built: `user_settings` table, `SettingKey` enum, `HasSettings` trait on `User`, and factory/tests (spec 013).
- The current settings page (`resources/views/settings.blade.php`) is a placeholder with no form.
- `SettingController` is an invokable controller returning a view with no data.
- There is no POST route for updating settings.
- The existing `SettingKey` enum has a single case: `DaftraDefaultClientId = 'daftra.default_client_id'`.

### Decisions

| Concern | Decision |
|---------|----------|
| Form handling | Single form with all settings, POST to a dedicated update endpoint |
| Validation | `UpdateSettingsRequest` form request |
| Flash feedback | Redirect back with status message on success |
| Architecture | Controller passes current settings to the view; form request validates; controller persists via `HasSettings` trait |

---

## Route

```
POST /settings → SettingController@update → named('settings.update')
```

- Must be within the `auth` middleware group.
- Existing `GET /settings` route stays unchanged (named `settings`).

---

## Requirements

### 1. Controller — `SettingController`

Replace the invokable `__invoke` with two methods:

```php
public function index()
{
    return view('settings', [
        'daftraDefaultClientId' => auth()->user()->setting(SettingKey::DaftraDefaultClientId),
    ]);
}

public function update(UpdateSettingsRequest $request)
{
    $user = $request->user();

    $user->setSetting(
        SettingKey::DaftraDefaultClientId,
        $request->input('daftra_default_client_id'),
    );

    return redirect()->route('settings')
        ->with('status', 'Settings updated successfully.');
}
```

Notes:
- `index()` replaces `__invoke()`. Route definition changes from invokable to `['GET', '/settings', [SettingController::class, 'index']]`.
- `update()` receives the validated form request and persists the setting via the existing `setSetting()` trait method.

### 2. Form Request — `UpdateSettingsRequest`

```
rules:
  daftra_default_client_id  nullable|string|max:255
```

- `nullable` — the user may clear the field to remove the default client.
- `string` — all settings are stored as strings.
- `max:255` — reasonable upper bound for a client ID.

### 3. Routes — `routes/web.php`

Update the settings route to use explicit method references:

```php
Route::get('/settings', [SettingController::class, 'index'])->name('settings');
Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
```

### 4. Blade View — `resources/views/settings.blade.php`

Replace the placeholder with a real form. The page follows the same design language as other pages (card with white/dark background, consistent spacing and typography).

#### Layout

```
┌─────────────────────────────────────────────────┐
│ Settings                                         │
│                                                  │
│ ┌─────────────────────────────────────────────┐ │
│ │ Daftra Integration                          │ │
│ │                                             │ │
│ │ Default Client ID                           │ │
│ │ ┌───────────────────────────────┐           │ │
│ │ │ (text input)                  │           │ │
│ │ └───────────────────────────────┘           │ │
│ │ Client used when a Foodics order has        │ │
│ │ no customer (walk-in).                      │ │
│ │                                             │ │
│ │                          [Save Settings]    │ │
│ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

#### Form details

- Method: `POST` to `route('settings.update')`.
- `@csrf` token.
- Single text input `daftra_default_client_id`, pre-filled with the current value (or empty if `null`).
- Help text below the input explaining the setting's purpose.
- Submit button styled consistently with the app's primary action buttons (Daftra blue `#4A90D9`, white text, rounded).
- Flash status message displayed at the top of the card when `session('status')` is present.
- Validation errors displayed inline using `@error('daftra_default_client_id')`.

#### Styling

Follow existing conventions from `invoices.blade.php` and `dashboard.blade.php`:
- Card: `bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6`
- Labels: `block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1`
- Inputs: `w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition`
- Help text: `mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]`
- Button: `inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors`
- Error text: `mt-1 text-xs text-red-600 dark:text-red-400`

---

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| User clears the field and saves | `setSetting()` stores `null` — walk-in orders will have no default client (current pre-setting behaviour) |
| User enters a non-numeric string | Accepted — stored as string. Cast to `int` happens at the call site in `SyncOrder`, non-numeric strings become `0` (existing behaviour) |
| Unauthenticated user hits POST /settings | Redirected to `/login` by `auth` middleware |
| Validation fails | Redirected back with errors, old input preserved |

---

## Files to Create

### 1. `app/Http/Requests/UpdateSettingsRequest.php`

Form request with validation rules for `daftra_default_client_id` (nullable, string, max:255).

## Files to Modify

### 2. `app/Http/Controllers/SettingController.php`

- Replace `__invoke()` with `index()` (passes `daftraDefaultClientId` to view).
- Add `update(UpdateSettingsRequest $request)` method.

### 3. `routes/web.php`

- Change settings GET route from invokable to `[SettingController::class, 'index']`.
- Add `POST /settings` route pointing to `[SettingController::class, 'update']`.

### 4. `resources/views/settings.blade.php`

- Replace placeholder with real form containing the Daftra Default Client ID input, help text, submit button, flash message, and validation error display.

## Tests

### `tests/Feature/SettingsPageTest.php`

- Guest cannot access GET /settings (redirect to login).
- Guest cannot POST to /settings (redirect to login).
- Authenticated user sees the settings form with current value pre-filled.
- Authenticated user can set the Daftra default client ID.
- Authenticated user can clear the Daftra default client ID (set to null).
- Validation rejects strings longer than 255 characters.
- Flash message appears after successful save.
- Old input is preserved on validation failure.

---

## Tasks

- [x] Create `app/Http/Requests/UpdateSettingsRequest.php`
- [x] Update `app/Http/Controllers/SettingController.php` (replace `__invoke` with `index` + `update`)
- [x] Update `routes/web.php` (split GET/POST, named routes)
- [x] Update `resources/views/settings.blade.php` (full form UI)
- [x] Write `tests/Feature/SettingsPageTest.php`
- [x] Run `php artisan test --compact --filter=SettingsPage`
- [x] Run `vendor/bin/pint --dirty --format agent`

---

## Out of Scope

- No connection management UI (reconnect/disconnect Daftra or Foodics).
- No additional settings beyond `DaftraDefaultClientId` (the page structure supports adding more cards/fields as new `SettingKey` cases are introduced).
- No API/JSON endpoint (settings are only managed via the web UI).
- No audit log for setting changes.
- No real-time validation or auto-save.
