# 008 - Login UI with Provider State

## Overview

Add a login page that shows the connection state of each provider based on session data. This spec also updates spec 007's callback behavior: instead of redirecting directly to the other provider's auth route, callbacks redirect to the login page where the user sees their progress and clicks to connect the remaining provider.

Protect authenticated routes with Laravel's `auth` middleware so unauthenticated users are redirected to the login page.

## Context

- Spec 007 stores provider account info in session (`daftra_account`, `foodics_account`) during the OAuth flow.
- Spec 007 currently redirects directly from one provider's callback to the other provider's auth route (e.g. `route('foodics.auth')`). This spec changes that behavior to redirect to the login page instead.
- The login page reads session data to determine which providers are already connected and displays the appropriate UI state.
- The existing `welcome.blade.php` will remain as the home page for authenticated users.
- The app uses Blade templates with Tailwind CSS (via Vite).
- `bootstrap/app.php` currently has an empty middleware configuration.

## Updated Flow (overrides spec 007)

```
Option A: User starts from inside Daftra
─────────────────────────────────────────
1. Daftra redirects to GET /daftra/auth/callback?code=abc
   → daftraCallback stores daftra_account in session
   → No foodics_account in session → redirect to /login
2. Login page shows: Daftra ✓ Connected, Foodics → "Connect" button
3. User clicks "Connect with Foodics" → GET /foodics/auth → redirect to Foodics
4. foodicsCallback → stores foodics_account → has daftra_account → loginOrCreateUser → home

Option B: User starts from inside Foodics (symmetric)
─────────────────────────────────────────────────────
1. Foodics redirects to GET /foodics/auth/callback?code=abc
   → foodicsCallback stores foodics_account in session
   → No daftra_account in session → redirect to /login
2. Login page shows: Foodics ✓ Connected, Daftra → "Connect" button
3. User clicks "Connect with Daftra" → GET /daftra/auth → redirect to Daftra
4. daftraCallback → stores daftra_account → has foodics_account → loginOrCreateUser → home

Option C: User starts from our login page
─────────────────────────────────────────
1. User visits /login → sees both providers as "Connect"
2. User clicks "Connect with Daftra" → GET /daftra/auth → redirect to Daftra
3. daftraCallback → stores daftra_account → no foodics → redirect to /login
4. Login page shows: Daftra ✓ Connected, Foodics → "Connect" button
5. User clicks "Connect with Foodics" → GET /foodics/auth → redirect to Foodics
6. foodicsCallback → stores foodics_account → has daftra → loginOrCreateUser → home
```

## Provider States

The login page checks the session for `daftra_account` and `foodics_account` and renders each provider in one of two states:

| State | Condition | UI |
| ----- | --------- | -- |
| **Connected** | `session()->has('daftra_account')` or `session()->has('foodics_account')` | Green checkmark, provider name, disabled button |
| **Pending** | Not in session | "Connect with {Provider}" button linking to the respective auth route |

Safety check: if both providers are in session, the controller redirects to home (shouldn't happen — spec 007's `loginOrCreateUser` runs before this).

## Routes

### New Route

```
GET /login → AuthController@loginForm → named('login')
```

This must be named `login` because the existing `welcome.blade.php` references `route('login')` in the nav.

### Route Protection

In `bootstrap/app.php`, configure the middleware to redirect unauthenticated users to `/login`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo('/login');
})
```

### Route Groups in `routes/web.php`

```php
// Guest routes (no auth required)
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::get('/daftra/auth', ...)->name('daftra.auth');
Route::get('/daftra/auth/callback', ...)->name('daftra.callback');
Route::get('/foodics/auth', ...)->name('foodics.auth');
Route::get('/foodics/auth/callback', ...)->name('foodics.callback');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('home');
});

// Webhooks (no auth, own middleware)
Route::post('webhooks/foodics', ...);
```

## Files to Create

### 1. `resources/views/login.blade.php`

Split-screen layout with provider connection buttons showing state based on session:

**Overall Layout:**
- Full-viewport split screen: left panel (40%) + right panel (60%)
- Left panel hidden on mobile (`hidden lg:block`)
- Use existing Tailwind/Vite assets and fonts (Instrument Sans from `welcome.blade.php`)
- Support dark mode (follow `welcome.blade.php` dark class patterns)

**Left Panel (hidden on mobile):**
- Solid background color — use a subtle gradient or pattern
- Decorative abstract illustration or geometric pattern suggesting integration/connectivity between two systems
- App name (`config('app.name')`) displayed at the bottom or center
- Visually distinct from the right panel (darker or colored background)

**Right Panel:**
- White background (dark: `#0a0a0a` or `#161615` matching existing theme)
- Centered content vertically and horizontally
- Brief instruction text at the top: "Connect both accounts to get started"
- Two large branded buttons stacked vertically with spacing:

**Daftra button (brand blue: `#4A90D9`):**
```
@if (session()->has('daftra_account'))
    ✓ Daftra Connected
    - Green background/bg-opacity variant
    - Checkmark icon
    - Disabled, non-clickable
    - Subtle account info (e.g. subdomain) shown below
@else
    [Daftra logo/icon] Connect with Daftra
    - Blue (#4A90D9) background, white text
    - Full-width button, rounded
    - Links to route('daftra.auth')
    - Hover: darker blue
@endif
```

**Foodics button (brand orange: `#FF4433`):**
```
@if (session()->has('foodics_account'))
    ✓ Foodics Connected
    - Green background/bg-opacity variant
    - Checkmark icon
    - Disabled, non-clickable
    - Subtle account info (e.g. business name) shown below
@else
    [Foodics logo/icon] Connect with Foodics
    - Orange (#FF4433) background, white text
    - Full-width button, rounded
    - Links to route('foodics.auth')
    - Hover: darker red
@endif
```

**Connected state details:**
- Green badge with checkmark icon replaces the brand-colored button
- Green tint: `bg-green-50 dark:bg-green-900/20`, green border, green text
- Show the account name from session below the badge (Daftra: show subdomain, Foodics: show business_name)

**Mobile layout:**
- Left panel is hidden entirely
- Right panel takes full width
- Same buttons stacked vertically, centered

## Files to Modify

### 2. `app/Http/AuthController.php`

**Add `loginForm` method:**

```php
public function loginForm()
{
    if (auth()->check()) {
        return redirect()->route('home');
    }

    if (session()->has('daftra_account') && session()->has('foodics_account')) {
        return redirect()->route('home');
    }

    return view('login');
}
```

**Update spec 007 callbacks** — change redirect targets from the other provider's auth route to the login page:

`daftraCallback` — change:
```
- redirect to route('foodics.auth')
+ redirect to route('login')
```

`foodicsCallback` — change:
```
- redirect to route('daftra.auth')
+ redirect to route('login')
```

### 3. `routes/web.php`

- Add `GET /login` route named `login`
- Wrap the home route in a `middleware('auth')` group
- Keep auth callback routes outside the middleware group

### 4. `bootstrap/app.php`

Configure guest redirect in middleware:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo('/login');
})
```

## Edge Cases

- **Both providers in session but user not logged in:** The `loginForm` method checks for this and redirects to home. Since spec 007's `loginOrCreateUser` should have run, this is a safety net.
- **Already authenticated:** If a logged-in user visits `/login`, redirect to home. Can be handled via `guest` middleware on the login route.
- **Session expires between flows:** The first provider's session data is lost. The login page shows both providers as pending — the user starts over.
- **Direct access to home:** Unauthenticated users hitting `/` get redirected to `/login`.

## Tasks

- [x] Add `loginForm` method to `AuthController`
- [x] Update `daftraCallback` to redirect to `route('login')` instead of `route('foodics.auth')`
- [x] Update `foodicsCallback` to redirect to `route('login')` instead of `route('daftra.auth')`
- [x] Create `resources/views/login.blade.php` with provider state cards
- [x] Add `/login` route to `routes/web.php`
- [x] Wrap home route in `auth` middleware group in `routes/web.php`
- [x] Configure `redirectGuestsTo('/login')` in `bootstrap/app.php`
