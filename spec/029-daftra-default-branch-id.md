# Spec: Daftra Default Branch ID Setting

## Summary

Add a per-user setting for the Daftra default branch ID. When the configured value differs from `1`, all Daftra API requests must include `?request_branch_id=<value>` as a query parameter.

## Background

Daftra supports multi-branch operations. By default, branch ID `1` is used and does not require explicit specification. Users operating in a non-default branch need every API call to carry `request_branch_id` so Daftra routes the operation to the correct branch.

## Current Architecture (affected areas)

| Layer | File | Role |
|-------|------|------|
| Enum | `app/Enums/SettingKey.php` | Defines valid setting keys |
| Trait | `app/Models/Concerns/HasSettings.php` | `setting()` / `setSetting()` helpers on User |
| API Client | `app/Services/Daftra/DaftraApiClient.php` | Central HTTP proxy; forwards `get`, `post`, `put`, `patch`, `delete` via `__call` |
| Controller | `app/Http/Controllers/SettingController.php` | Reads/writes settings |
| Request | `app/Http/Requests/UpdateSettingsRequest.php` | Validates settings form |
| View | `resources/views/settings.blade.php` | Settings form UI |

---

## Changes

### 1. Add `DaftraDefaultBranchId` to `SettingKey` enum

**File:** `app/Enums/SettingKey.php`

Add a new case:

```php
case DaftraDefaultBranchId = 'daftra.default_branch_id';
```

No migration needed — the existing `user_settings` table stores arbitrary enum-backed keys.

---

### 2. Inject branch query parameter in `DaftraApiClient`

**File:** `app/Services/Daftra/DaftraApiClient.php`

This is the single point where all Daftra HTTP calls pass through (`__call` proxies `get`, `post`, `put`, `patch`, `delete`). The branch parameter should be appended here so every service class automatically benefits.

**Logic:**

```
1. Read the user's DaftraDefaultBranchId setting
2. If the value is null, empty, or "1" → do nothing
3. Otherwise, append ?request_branch_id=<value> to the URL query string
   - Applies to ALL methods: GET, POST, PUT, PATCH, DELETE
```

**Implementation approach:**

- Inject the branch ID at construction time (read from `$user->setting(SettingKey::DaftraDefaultBranchId)`) and store it as a class property.
- In `__call`, before forwarding the call, append `request_branch_id` as a **URL query parameter** by modifying the URL (first argument) for all HTTP methods.
  - Parse the existing URL, merge `request_branch_id` into the query string, and rebuild it.
  - This ensures `request_branch_id` is sent as a query param even on POST/PUT/PATCH requests.

**Pseudocode:**

```php
public function __construct(protected User $user)
{
    $this->branchId = $user->setting(SettingKey::DaftraDefaultBranchId);
    // ... existing client setup
}

public function __call($name, $arguments)
{
    if (in_array($name, ['get', 'post', 'put', 'patch', 'delete'])) {
        $arguments[0] = $this->appendBranchIdToUrl($arguments[0]);
    }

    // ... existing retry logic (refreshToken is called internally,
    // and refreshToken() calls $this->client->post() directly,
    // NOT through __call, so branch ID is NOT appended to /oauth/token)
}

private function appendBranchIdToUrl(string $url): string
{
    if ($this->branchId === null || $this->branchId === '' || $this->branchId === '1') {
        return $url;
    }

    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . 'request_branch_id=' . $this->branchId;
}
```

> **Note 1:** By appending to the URL directly (not the body), `request_branch_id` is always sent as a query parameter regardless of the HTTP method — which is exactly how Daftra expects it, even on POST/PUT/PATCH calls.
>
> **Note 2:** The token refresh call in `refreshToken()` uses `$this->client->post()` directly (bypasses `__call`), so `request_branch_id` is **not** appended to `POST /oauth/token`. This is intentional — auth endpoints do not need a branch context.

---

### 3. Update `UpdateSettingsRequest` validation

**File:** `app/Http/Requests/UpdateSettingsRequest.php`

Add a validation rule for the new field:

```php
'daftra_default_branch_id' => ['nullable', 'integer', 'min:1'],
```

> **Branch existence validation** — Ideally, we'd validate the branch exists in Daftra by calling the Daftra API during save. However, this would require the user to be authenticated with Daftra at the time of saving (which they should be). We can add a **custom rule** that calls the Daftra API to verify the branch exists. This will be a follow-up enhancement; for the initial implementation, we validate it's a positive integer.

**Future enhancement (branch existence check):**

Create a custom validation rule `ValidDaftraBranch` that:
1. Resolves the current user's `DaftraApiClient`
2. Calls a Daftra branch list/show endpoint (e.g., `GET /api2/branches` or equivalent)
3. Fails validation if the branch ID is not found

This can be added once the Daftra branch API endpoint is confirmed.

---

### 4. Update `SettingController`

**File:** `app/Http/Controllers/SettingController.php`

- In `index()`: pass `daftraDefaultBranchId` to the view (alongside existing `daftraDefaultClientId`).
- In `update()`: persist the new setting using `$request->user()->setSetting(SettingKey::DaftraDefaultBranchId, ...)`.

---

### 5. Update settings view

**File:** `resources/views/settings.blade.php`

Add a new "Default Branch ID" input field inside the existing "Daftra Integration" card, following the same styling as "Default Client ID":

- **Field name:** `daftra_default_branch_id`
- **Label:** "Default Branch ID"
- **Input type:** `type="text"` (consistent with existing Default Client ID field)
- **Help text:** "Daftra branch ID used for all API requests."
- **Placeholder:** "1"

Position it above the "Default Client ID" field.

---

### 6. Update `AppServiceProvider` binding (no change needed)

The `DaftraApiClient` is already bound per-resolve using `Context::get('user')`. Since the branch ID is read from the user's settings inside the constructor, no service provider changes are needed.

---

## Files Changed (summary)

| File | Change |
|------|--------|
| `app/Enums/SettingKey.php` | Add `DaftraDefaultBranchId` case |
| `app/Services/Daftra/DaftraApiClient.php` | Read branch setting; append `request_branch_id` to all API calls |
| `app/Http/Requests/UpdateSettingsRequest.php` | Add `daftra_default_branch_id` validation rule |
| `app/Http/Controllers/SettingController.php` | Read/write the new setting |
| `resources/views/settings.blade.php` | Add "Default Branch ID" form field |

---

## Behavior Matrix

| Setting Value | `request_branch_id` sent? |
|---------------|--------------------------|
| Not set (null) | No |
| Empty string | No |
| `"1"` | No |
| `"2"` | Yes (`request_branch_id=2`) |
| `"15"` | Yes (`request_branch_id=15`) |

---

## Testing Plan

### Unit Tests

1. **`DaftraApiClient` appends `request_branch_id` to GET requests** when branch ID is not 1.
2. **`DaftraApiClient` appends `request_branch_id` to POST requests** when branch ID is not 1.
3. **`DaftraApiClient` does NOT append `request_branch_id`** when branch ID is 1, null, or empty.
4. **`DaftraApiClient` preserves existing query/body params** when appending branch ID.

### Feature Tests

5. **Settings page displays current branch ID** value.
6. **Settings form saves branch ID** correctly.
7. **Validation rejects non-integer and negative values**.
8. **Validation accepts null and positive integers**.
9. **Full sync flow works** with a non-default branch ID (verify `request_branch_id` is present in HTTP calls).
10. **Full sync flow works** with default branch ID (verify `request_branch_id` is absent).

### Existing Tests

No changes to existing tests are needed. Since the branch ID setting won't be set in existing test contexts (null), the `appendBranchIdToUrl` method will return early and no query parameter will be appended — preserving all current behavior.
