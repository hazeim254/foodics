# 004 - Foodics API Client

## Overview

Create a `FoodicsApiClient` service class that mirrors the existing `DaftraApiClient` pattern. The client will handle authenticated HTTP requests to the Foodics API with automatic token refresh on 401 responses.

## Context

- `DaftraApiClient` already exists at `app/Services/Daftra/DaftraApiClient.php` and provides the exact pattern to follow.
- Foodics OAuth is already implemented in `AuthController::foodicsCallback()` — tokens are stored in the `provider_tokens` table with `provider = 'foodics'`.
- The `ProviderToken` model stores encrypted `token`, `refresh_token`, and `expires_at`.
- Foodics config already exists in `config/services.php` under the `foodics` key with `oauth_url`, `client_id`, `client_secret`, and `redirect_uri`.
- The `AuthController` currently hardcodes `https://api-sandbox.foodics.com` as the base URL. A dedicated `base_url` config key will centralize this.

## Files to Create

### 1. `app/Services/Foodics/FoodicsApiClient.php`

Mirrors `DaftraApiClient` structure exactly.

**Constructor:**
- Accepts a `User` instance
- Builds an HTTP client: `\Http::asJson()->acceptJson()->baseUrl(config('services.foodics.base_url'))->withToken($user->getFoodicsToken())`

**`__call` magic method:**
- Proxies all method calls to the underlying `PendingRequest`
- For HTTP verbs (`get`, `post`, `put`, `patch`, `delete`):
  - Execute the request
  - If 401 response, call `refreshToken()` and retry the request
  - Return the response

**`refreshToken()` method (private):**
- POST to `/oauth/token` with:
  - `refresh_token` from the user's Foodics provider token
  - `grant_type` = `'refresh_token'`
  - `client_id` from `config('services.foodics.client_id')`
  - `client_secret` from `config('services.foodics.client_secret')`
- On failure, throw an exception
- On success, update the `ProviderToken` in the database (`token`, `expires_at`)
- Update the HTTP client's bearer token

## Files to Modify

### 2. `config/services.php`

Add `'base_url'` key to the `foodics` section:

```php
'foodics' => [
    'oauth_url' => env('FOODICS_OAUTH_URL'),
    'base_url' => env('FOODICS_BASE_URL'),
    'client_id' => env('FOODICS_CLIENT_ID'),
    'client_secret' => env('FOODICS_CLIENT_SECRET'),
    'redirect_uri' => env('FOODICS_REDIRECT_URI'),
],
```

### 3. `app/Models/User.php`

Add `getFoodicsToken()` method (same pattern as `getDaftraToken()`):

```php
public function getFoodicsToken(): ?ProviderToken
{
    return $this->providerTokens->firstWhere('provider', 'foodics');
}
```

### 4. `app/Providers/AppServiceProvider.php`

Add a container binding for `FoodicsApiClient` (same pattern as the existing `DaftraApiClient` binding):

```php
$this->app->bind(FoodicsApiClient::class, function ($app) {
    $user = \Context::get('user');
    if (! $user) {
        throw new \Exception('User not found in context');
    }

    return new FoodicsApiClient($user);
});
```

## Data Flow

```
User context set via \Context::add('user', $user)
  → Container resolves FoodicsApiClient
      → Builds HTTP client with base_url + bearer token
          → HTTP calls proxied via __call
              → 401? → refresh token → retry
              → Otherwise → return response
```

## No New Migrations

The `provider_tokens` table already supports `provider = 'foodics'`. No schema changes needed.

## Tasks

- [x] Add `base_url` to foodics config in `config/services.php`
- [x] Add `getFoodicsToken()` method to `User` model
- [x] Create `app/Services/Foodics/FoodicsApiClient.php`
- [x] Add container binding for `FoodicsApiClient` in `AppServiceProvider`
- [x] Write tests for `FoodicsApiClient`
