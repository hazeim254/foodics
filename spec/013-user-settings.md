# User Settings Infrastructure + Daftra Default Client Setting

## Context

Introduce a per-user settings infrastructure so features can store arbitrary
user-scoped configuration. No UI is required at this stage — only the data layer,
model, enum-backed key registry, and convenience API on `User`.

The first (and currently only) registered setting is the **Daftra default client id**,
used by `SyncOrder` as a fallback when a Foodics order has no customer (walk-in).

### Decisions

| Concern | Decision |
|---------|----------|
| Storage | Dedicated `user_settings` table, one row per (user, key) |
| Key registry | Typed PHP Enum `App\Enums\SettingKey` (backed string) |
| Value type | `string` column — callers cast as needed |
| Access API | `$user->setting($key)` / `$user->setSetting($key, $value)` via a `HasSettings` trait |
| Defaults | No built-in defaults — returns `null` when unset; caller handles fallback |
| First setting | `SettingKey::DaftraDefaultClientId` (value = string form of the Daftra client id) |

---

## Data Layer

### Migration: `create_user_settings_table`

```
user_settings
-------------
id            bigint PK
user_id       bigint FK -> users.id (cascade on delete)
key           string  (enum value, e.g. "daftra.default_client_id")
value         text    (nullable; all values stored as string)
created_at    timestamp
updated_at    timestamp

UNIQUE (user_id, key)
INDEX (user_id)
```

Notes:
- `value` is `text` rather than `string` so we don't arbitrarily cap length — some future
  settings may store longer payloads even though they remain string-typed.
- The `(user_id, key)` unique constraint guarantees single-row-per-setting semantics and
  enables safe `updateOrCreate` upserts.

### Model: `App\Models\UserSetting`

- `$fillable = ['user_id', 'key', 'value']`
- Casts: `key` cast to `SettingKey` enum
- Relationship: `user(): BelongsTo`
- Factory: `UserSettingFactory` (generates a valid enum key + random string value)

### Enum: `App\Enums\SettingKey`

```php
enum SettingKey: string
{
    case DaftraDefaultClientId = 'daftra.default_client_id';
}
```

- Backed string enum. Values use dot-notation namespaced keys (e.g. `daftra.*`,
  `foodics.*`) so future settings from other providers group naturally.
- Adding a new setting = adding a new case. No migrations needed.

---

## Model API on `User`

### Trait: `App\Models\Concerns\HasSettings`

Lives next to other model concerns; applied on `User`.

Provides:

- `settings(): HasMany` — relationship to `UserSetting`.
- `setting(SettingKey $key): ?string` — returns the stored string value, or `null` if the
  key has no row for this user. Uses the loaded `settings` collection if already eager-loaded,
  otherwise queries directly.
- `setSetting(SettingKey $key, ?string $value): UserSetting` — `updateOrCreate` on
  `(user_id, key)`. Passing `null` clears the value (stores null, does not delete row).
- `forgetSetting(SettingKey $key): void` — deletes the row (rare; exposed for completeness).

All methods accept only `SettingKey` instances — passing a string is a type error. This
forces every call site through the enum registry.

### Usage example

```php
$user->setSetting(SettingKey::DaftraDefaultClientId, '12345');

$defaultClientId = $user->setting(SettingKey::DaftraDefaultClientId);
// => "12345" or null
```

---

## Runtime Usage: Walk-in Fallback in `SyncOrder`

`SyncOrder::handle()` currently does:

```php
$clientId = null;
if (! empty($order['customer'])) {
    $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
}
```

Change to fall back on the per-user default when the order has no customer:

```php
$clientId = null;
if (! empty($order['customer'])) {
    $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
} else {
    $user = Context::get('user');
    $default = $user?->setting(SettingKey::DaftraDefaultClientId);
    $clientId = $default !== null ? (int) $default : null;
}
```

Notes:
- The user is resolved from `Context::get('user')` (same pattern as existing services).
- Cast to `int` at the call site since the setting is stored as a string. If the user has
  no default configured, `client_id` stays `null` — same behaviour as today.
- No changes to `ClientService` are needed.

---

## Files to Create / Modify

### Create

1. `database/migrations/<ts>_create_user_settings_table.php` — schema above.
2. `app/Enums/SettingKey.php` — backed string enum with `DaftraDefaultClientId` case.
3. `app/Models/UserSetting.php` — Eloquent model, fillable, enum cast, `user()` relation.
4. `app/Models/Concerns/HasSettings.php` — trait with `settings()`, `setting()`,
   `setSetting()`, `forgetSetting()`.
5. `database/factories/UserSettingFactory.php` — factory with random enum key + string value.

### Modify

6. `app/Models/User.php` — `use HasSettings;`.
7. `app/Services/SyncOrder.php` — fallback to `SettingKey::DaftraDefaultClientId` when
   order has no customer.

---

## Tests (Pest, feature-level)

### `tests/Feature/UserSettingsTest.php`

- `setting()` returns `null` when no row exists for the key.
- `setSetting()` creates a row for a new key.
- `setSetting()` updates the value when the key already exists (no duplicate rows).
- `setSetting(null)` stores `null` without deleting the row.
- `forgetSetting()` removes the row.
- Deleting the user cascades and removes their settings rows.
- Enforcing `(user_id, key)` uniqueness at the DB level (attempting a raw duplicate throws).

### `tests/Feature/SyncOrder/WalkInDefaultClientTest.php`

- When an order has **no customer** and the acting user has
  `SettingKey::DaftraDefaultClientId` set → the created Daftra invoice payload uses that
  client id.
- When an order has no customer and the setting is **unset** → `client_id` is `null`
  (current behaviour preserved).
- When an order **has a customer** → `ClientService::getClientUsingFoodicsData()` is used
  and the default setting is ignored.

Mock `InvoiceService`, `ClientService`, and other collaborators using the existing
test style (see current `SyncOrder` tests). Keep the fixture orders minimal — reuse
shapes from `json-stubs/foodics/get-order.json` if helpful.

---

## TODO List

- [ ] Create migration `create_user_settings_table` with `(user_id, key)` unique index
- [ ] Create `App\Enums\SettingKey` with `DaftraDefaultClientId`
- [ ] Create `App\Models\UserSetting` with enum cast on `key`
- [ ] Create `App\Models\Concerns\HasSettings` trait
- [ ] Create `UserSettingFactory`
- [ ] Apply `HasSettings` trait on `User`
- [ ] Write `tests/Feature/UserSettingsTest.php` covering get/set/update/null/forget/cascade/uniqueness
- [ ] Update `SyncOrder::handle()` to use the setting as walk-in fallback
- [ ] Write `tests/Feature/SyncOrder/WalkInDefaultClientTest.php`
- [ ] `php artisan migrate`
- [ ] `php artisan test --compact --filter="UserSettings|WalkInDefaultClient"`
- [ ] `vendor/bin/pint --dirty --format agent`

---

## Out of Scope (Explicit)

- No UI / HTTP routes / controllers / form requests.
- No admin or API endpoints for managing settings.
- No caching layer — settings are read via Eloquent each call (optimize later if a hot path emerges).
- No encryption at rest — values are plain strings. If a future setting is sensitive
  (token, secret), either add a `is_encrypted` column or a per-key cast strategy at that time.
- No typed casting in the registry — every value is a `string`. Typed accessors
  (`SettingKey::Foo->cast($value)`) can be added when a non-string setting arrives.
- No defaults in the registry — `setting()` returns `null` when unset.
