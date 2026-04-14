# 007 - Auth Workflow: Session-Based Dual Provider Login

## Overview

Rewrite the OAuth callback flow so both Daftra and Foodics callbacks follow the same symmetric pattern: exchange the authorization code for tokens, fetch account info, store it in session, then check if the other provider's info is already in session. When both are present, find or create a user and log them in.

## Context

- **Current state:** `AuthController::foodicsCallback` is implemented but assumes an already-logged-in user (local dev auto-login). `daftraCallback` is a stub that only checks `foodics_id` (wrong column — should be `foodics_ref`).
- **Local dev auto-login** (`Auth::loginUsingId(1)` in `routes/web.php`) must be removed once this flow is in place.
- **No user exists yet at callback time** — the whole point of this flow is to create/login the user.
- `ProviderToken` model stores encrypted `token`, `refresh_token`, and `expires_at` per provider.
- `users` table has `daftra_id` (string, nullable) and `foodics_ref` (string, nullable) columns.
- `DaftraApiClient` references `config('daftra.*')` keys that don't exist — needs aligning to `config('services.daftra.*')` like Foodics.
- Both providers use the same OAuth authorization code grant pattern.
- **Flow can originate from inside Daftra or Foodics** (user clicks "Connect" in their provider dashboard) — our callback is hit directly without us initiating the redirect. CSRF state validation must be conditional.

## Flow Diagram

```
Option A: User starts from inside Daftra (most common)
─────────────────────────────────────────────────────
1. User clicks "Connect to Foodics" in Daftra dashboard
2. Daftra redirects to GET /daftra/auth/callback?code=abc
   → daftraCallback:
     a. No daftra_state in session (external initiation) → skip CSRF check
     b. Exchange code → tokens (response includes site_id + subdomain)
     c. Store daftra_account in session
     d. Check session for foodics_account → NOT FOUND
     e. Redirect to GET /foodics/auth
3. GET /foodics/auth → foodicsRedirect → sets foodics_state → redirect to Foodics authorize URL
4. Foodics redirects to GET /foodics/auth/callback?code=xyz&state=...
   → foodicsCallback:
     a. Validate foodics_state (we initiated this one)
     b. Exchange code → tokens
     c. Fetch Foodics whoami → extract business info
     d. Store foodics_account in session
     e. Check session for daftra_account → FOUND
     f. loginOrCreateUser() → login → redirect home

Option B: User starts from inside Foodics (symmetric)
─────────────────────────────────────────────────────
1. User clicks "Connect to Daftra" in Foodics console
2. Foodics redirects to GET /foodics/auth/callback?code=abc
   → foodicsCallback:
     a. No foodics_state in session (external initiation) → skip CSRF check
     b. Exchange code → tokens
     c. Fetch Foodics whoami → extract business info
     d. Store foodics_account in session
     e. No daftra_account in session → redirect to GET /daftra/auth
3. GET /daftra/auth → daftraRedirect → sets daftra_state → redirect to Daftra authorize URL
4. daftraCallback → validates state → stores daftra_account → has foodics_account → loginOrCreateUser → home

Option C: User starts from our app
───────────────────────────────────
1. GET /daftra/auth → daftraRedirect → sets daftra_state → redirect to Daftra
2. daftraCallback → validates daftra_state → stores daftra_account → no foodics → redirect to /foodics/auth
3. GET /foodics/auth → foodicsRedirect → sets foodics_state → redirect to Foodics
4. foodicsCallback → validates foodics_state → stores foodics_account → has daftra → loginOrCreateUser → home
```

## Provider Data Structures

### Daftra Token Response

Daftra's OAuth token endpoint returns additional keys beyond the standard OAuth response:

```json
{
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 3600,
  "site_id": 12345,
  "subdomain": "example"
}
```

`site_id` is used as `daftra_id`. The subdomain and future Daftra-specific data go into the `daftra_meta` JSON column.

No additional API call is needed to fetch Daftra account info — everything comes from the token response. However, a `/users/me` call is still needed to get `name` and `email` for new user creation.

### Foodics Whoami Response

Foodics' `GET /whoami` endpoint returns business, user, and scope information:

```json
{
  "data": {
    "business": {
      "id": "9598b465-dd92-4eba-a2e0-ac7cd1d2cca0",
      "name": "Alex Ralph's Business",
      "country_iso_code": "SA",
      "reference": 526215
    },
    "user": {
      "id": "9598b465-c80a-441c-b383-2890746b1a08",
      "name": "Alex Ralph",
      "email": "alexralph@foodics.com"
    },
    "scopes": ["general.read", "orders.list"]
  }
}
```

Fields we need to extract:
- `business.id` → `foodics_id` on user (the business UUID, different from `foodics_ref`)
- `business.reference` → `foodics_ref` on user (the numeric business reference)
- `business.name` → stored in `foodics_meta`
- `user.name` / `user.email` → fallback for new user creation (prefer Daftra)

### Users Table Schema Changes

Replace the existing `daftra_id`, `daftra_subdomain`, and `foodics_ref` columns with a cleaner design using JSON meta columns for extensibility:

| Column          | Type            | Description                                    |
| --------------- | --------------- | ---------------------------------------------- |
| `daftra_id`     | string, nullable | Daftra `site_id` (used for user matching)     |
| `foodics_ref`   | string, nullable | Foodics `business.reference` (used for user matching + webhook lookup) |
| `foodics_id`    | string, nullable | Foodics `business.id` (UUID)                  |
| `daftra_meta`   | json, nullable  | Daftra provider data (`{subdomain, ...}`)      |
| `foodics_meta`  | json, nullable  | Foodics provider data (`{business_name, ...}`) |

The meta columns allow storing additional provider-specific data without schema changes. Currently:

**`daftra_meta` structure:**
```json
{
  "subdomain": "example"
}
```

**`foodics_meta` structure:**
```json
{
  "business_name": "Alex Ralph's Business",
  "business_id": "9598b465-dd92-4eba-a2e0-ac7cd1d2cca0"
}
```

## Session Encryption

OAuth tokens (`access_token`, `refresh_token`) are stored in the session between provider redirects. Laravel's session driver stores session payloads in the database/file by default without encryption. To protect tokens at rest, use Laravel's built-in `EncryptCookies` middleware which encrypts the entire session payload, or configure the session encryption option.

**Approach:** Enable session encryption via `config/session.php`:

```php
'encrypt' => true,
```

This encrypts the entire session payload before writing to storage and decrypts on read. No code changes needed in the callbacks — `session()->get()` / `session()->put()` work transparently.

**Alternative:** If session encryption is not desired globally, encrypt only the sensitive token values before storing in session and decrypt after reading:

```php
// Storing
$request->session()->put('daftra_account', [
    'site_id' => $result['site_id'],
    'subdomain' => $result['subdomain'],
    'name' => $user['name'],
    'email' => $user['email'],
    'access_token' => Crypt::encryptString($result['access_token']),
    'refresh_token' => Crypt::encryptString($result['refresh_token']),
    'expires_in' => $result['expires_in'],
]);

// Reading
$daftra = $request->session()->get('daftra_account');
$accessToken = Crypt::decryptString($daftra['access_token']);
```

## Session Keys

| Key               | Type   | Description                                                          |
| ----------------- | ------ | -------------------------------------------------------------------- |
| `daftra_state`    | UUID   | CSRF state for Daftra OAuth (only set when we initiate the redirect) |
| `foodics_state`   | UUID   | CSRF state for Foodics OAuth (only set when we initiate the redirect) |
| `daftra_account`  | array  | `{site_id, subdomain, name, email, access_token, refresh_token, expires_in}` |
| `foodics_account` | array  | `{business_id, business_ref, business_name, access_token, refresh_token, expires_in}` |

## Files to Create

### 1. Migration: Add provider meta columns and `foodics_id` to users table

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('foodics_id')->nullable()->after('foodics_ref');
    $table->json('daftra_meta')->nullable()->after('daftra_id');
    $table->json('foodics_meta')->nullable()->after('foodics_id');
});
```

Also drop the existing `daftra_subdomain` column if it exists from a previous migration.

## Files to Modify

### 2. `config/services.php`

Add missing Daftra config keys to match the Foodics pattern:

```php
'daftra' => [
    'oauth_url' => env('DAFTRA_OAUTH_URL'),
    'base_url' => env('DAFTRA_BASE_URL'),
    'client_id' => env('DAFTRA_APP_ID'),
    'client_secret' => env('DAFTRA_APP_SECRET'),
    'redirect_uri' => env('DAFTRA_REDIRECT_URI'),
],
```

### 3. `routes/web.php`

Add initiation routes and remove local auto-login:

```php
// Remove this block:
if (app()->environment('local') && ! app()->runningUnitTests()) {
    Auth::loginUsingId(1);
}

// Add these routes:
Route::get('/daftra/auth', [AuthController::class, 'daftraRedirect'])->name('daftra.auth');
Route::get('/foodics/auth', [AuthController::class, 'foodicsRedirect'])->name('foodics.auth');
```

### 4. `app/Http/AuthController.php`

Rewrite entirely. Both callbacks follow the same 5-step pattern:

**`daftraRedirect(Request $request): RedirectResponse`** *(new)*
- Generate UUID state, store in session as `daftra_state`
- Redirect to `{config('services.daftra.oauth_url')}/authorize?client_id=...&state=...&redirect_uri=...`

**`foodicsRedirect(Request $request): RedirectResponse`** *(rewrite existing)*
- Generate UUID state, store in session as `foodics_state`
- Use `config('services.foodics.oauth_url')` instead of hardcoded URL
- Build redirect URL from config values

**CSRF State Validation (shared pattern for both callbacks):**

State validation is conditional — only validate when we initiated the OAuth redirect:

```php
if ($request->session()->has('daftra_state')) {
    if ($request->session()->get('daftra_state') !== $request->input('state')) {
        throw new BadRequestException('Invalid state parameter');
    }
}
```

This handles three scenarios:
- **External initiation** (user starts from Daftra/Foodics dashboard): No state in session, skip validation
- **Our initiation** (user starts from our app): State exists in session, validate it
- **Forged callback**: State exists but doesn't match, reject it

**`daftraCallback(Request $request): RedirectResponse`** *(rewrite)*
1. Validate CSRF state conditionally (see above)
2. Exchange code for tokens: POST to `{base_url}/oauth/token` with `grant_type=authorization_code`, `client_id`, `client_secret`, `code`, `redirect_uri`
3. Extract `site_id` and `subdomain` from token response
4. Fetch Daftra user info: GET `{base_url}/users/me` with bearer token → extract `name`, `email`
5. Store in session as `daftra_account`: `{site_id, subdomain, name, email, access_token, refresh_token, expires_in}`
6. If `foodics_account` not in session → redirect to `route('foodics.auth')`
7. Otherwise → call `loginOrCreateUser()` → redirect to `route('home')`

**`foodicsCallback(Request $request): RedirectResponse`** *(rewrite)*
1. Validate CSRF state conditionally (see above)
2. Exchange code for tokens: POST to `{base_url}/oauth/token` with same params (use `config('services.foodics.*')`)
3. Fetch Foodics whoami: GET `{base_url}/whoami` → extract:
   - `data.business.id` → `business_id`
   - `data.business.reference` → `business_ref`
   - `data.business.name` → `business_name`
4. Store in session as `foodics_account`: `{business_id, business_ref, business_name, access_token, refresh_token, expires_in}`
5. If `daftra_account` not in session → redirect to `route('daftra.auth')`
6. Otherwise → call `loginOrCreateUser()` → redirect to `route('home')`

**`loginOrCreateUser(Request $request): void`** *(new, private)*

Shared logic called by both callbacks when both provider accounts are in session:

```
$daftra = session->get('daftra_account')
$foodics = session->get('foodics_account')

1. Find existing user — BOTH provider IDs must match:
   $user = User::where('daftra_id', $daftra['site_id'])
       ->where('foodics_ref', $foodics['business_ref'])
       ->first()

2. If not found → create new user:
   - name: $daftra['name'] (prefer Daftra)
   - email: $daftra['email'] (prefer Daftra)
   - password: random 40-char string
   - daftra_id: $daftra['site_id']
   - daftra_meta: {subdomain: $daftra['subdomain']}
   - foodics_ref: $foodics['business_ref']
   - foodics_id: $foodics['business_id']
   - foodics_meta: {business_name: $foodics['business_name'], business_id: $foodics['business_id']}

3. Create/update provider tokens:
   - firstOrCreate for 'daftra' with daftra_account tokens
   - firstOrCreate for 'foodics' with foodics_account tokens

4. Auth::login($user)

5. Clear session: forget('daftra_account', 'foodics_account', 'daftra_state', 'foodics_state')
```

**Important:** The user lookup uses `AND` (not `OR`). Both `daftra_id` AND `foodics_ref` must match the same user row. If either ID differs from an existing user, a new user is created. This prevents accidental linking of unrelated provider accounts — a problem encountered in previous apps.

### 5. `app/Models/User.php`

Update `$fillable` and add `casts` for the JSON columns:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'daftra_id',
    'daftra_meta',
    'foodics_ref',
    'foodics_id',
    'foodics_meta',
];

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'daftra_meta' => 'array',
        'foodics_meta' => 'array',
    ];
}
```

### 6. `app/Services/Daftra/DaftraApiClient.php`

Update config references to use `config('services.daftra.*')`:

- `config('daftra.base_url')` → `config('services.daftra.base_url')`
- `config('daftra.client_id')` → `config('services.daftra.client_id')`
- `config('daftra.client_secret')` → `config('services.daftra.client_secret')`

## Edge Cases

- **Session expired between provider redirects:** If the user takes too long between the two OAuth flows, the first provider's session data will be lost. The second callback will redirect back to the first provider, restarting the cycle. Consider adding a session timeout check or flash message.
- **Same provider initiated twice:** If a user completes Daftra auth, gets redirected to Foodics, but then manually starts Daftra auth again, the `daftra_account` in session gets overwritten. This is harmless — it just refreshes the Daftra data.
- **Strict user matching:** Both `daftra_id` AND `foodics_ref` must match the same user. This prevents linking a Foodics account to the wrong Daftra account. If a user re-authenticates with a different provider account, a new user row is created rather than updating the existing one. This is intentional to avoid the data corruption issues seen in previous apps.
- **Token exchange failure:** If the token exchange fails, throw an exception with a user-friendly message. Do not store partial data in session.
- **External initiation without state:** When the flow starts from inside Daftra/Foodics, no `state` parameter may be included. The conditional check handles this gracefully — no state in session means no validation.

## Tasks

- [x] Create migration to add `foodics_id`, `daftra_meta`, and `foodics_meta` columns to users table
- [x] Enable session encryption in `config/session.php` (`'encrypt' => true`)
- [x] Add missing Daftra config keys to `config/services.php`
- [x] Update `User` model: `$fillable` and `casts()` for new columns
- [x] Update `DaftraApiClient` config key references
- [x] Add `daftraRedirect` method to `AuthController`
- [x] Rewrite `foodicsRedirect` to use config instead of hardcoded URLs
- [x] Rewrite `daftraCallback` with session-based flow and conditional CSRF validation
- [x] Rewrite `foodicsCallback` with session-based flow and conditional CSRF validation
- [x] Add `loginOrCreateUser` private method to `AuthController`
- [x] Add `/daftra/auth` and `/foodics/auth` routes
- [x] Remove local dev auto-login from `routes/web.php`
