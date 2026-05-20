# Refactor: Replace Context with Scoped UserContext (Container-Extended)

## Goal

Remove `Illuminate\Support\Facades\Context` as the mechanism for passing the current `User` through the service layer. Replace it with a **scoped `UserContext`** class that extends the container's own resolution mechanism — services type-hint `UserContext` in their constructors and the container auto-wires the full graph. No manual factory wiring, no global state leakage.

## Why Not a Factory (UserServices)?

A `UserServices` class with factory methods like `syncOrder()`, `invoiceService()`, etc. manually wires dependencies — this **replicates** what Laravel's container already does. Instead, we let the container resolve the full dependency graph by introducing a single scoped binding (`UserContext`) that services depend on. Entry points set the user once, then `app(SyncOrder::class)` Just Works.

## Design Principles

1. **Prefer dependency injection over `app()`** — Services should receive their dependencies via constructor injection, resolved by the container. Avoid `app(SomeService::class)` calls inside service methods. Use `app()` only where dependency injection is impractical (entry points like jobs, commands, handlers).
2. **`DaftraApiClient` and `FoodicsApiClient` depend on `UserContext`** — Not `User $user`. This eliminates custom container bindings entirely. The container resolves them automatically via auto-wiring: `UserContext` → `UserContext::get()` → `User`.
3. **`SetUserContext` middleware** — Sets `UserContext` from `auth()->user()` for all authenticated HTTP requests. No more `auth()->user()` fallback in bindings.

## Architecture

```
HTTP Request:
  SetUserContext middleware → app(UserContext::class)->set(auth()->user())
  Controller → container resolves services → UserContext auto-injected

Queue Job:
  handle() → app(UserContext::class)->set($this->user) ← first line
  Container resolves services → UserContext auto-injected

Console Command:
  handle() → app(UserContext::class)->set($user) ← first line
  Container resolves services → UserContext auto-injected

Service dependency graph (all auto-wired):
  SyncOrder
   ├─ InvoiceService(DaftraApiClient(UserContext))
   ├─ ProductService(DaftraApiClient(UserContext), UserContext)
   ├─ ClientService(DaftraApiClient(UserContext), UserContext)
   ├─ TaxService(DaftraApiClient(UserContext), UserContext)
   ├─ PaymentMethodService(DaftraApiClient(UserContext), UserContext)
   ├─ SyncCreditNote(InvoiceService, ProductService, ClientService, TaxService, UserContext)
   └─ UserContext (scoped — flushed per request/job by Octane)
```

## Critical Ordering Constraint

`UserContext` must be set **before** any service that depends on it is resolved from the container. For jobs, this means `app(UserContext::class)->set($this->user)` must be the first line in `handle()`, before any `app(Service::class)` calls. This is identical to the existing `Context::add('user', $user)` constraint — not a new risk, but must be explicit.

For HTTP requests, the `SetUserContext` middleware runs before controllers, so the user is always set.

---

## New Files

### 1. `app/Services/UserContext.php`

A simple scoped value holder for the current user. Registered as `scoped` in the container — Octane flushes it between requests/workers.

```php
<?php

namespace App\Services;

use App\Models\User;

class UserContext
{
    private ?User $user = null;

    public function set(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function get(): User
    {
        if ($this->user === null) {
            throw new \RuntimeException('UserContext has not been set. Call set() before accessing the user.');
        }

        return $this->user;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function flush(): void
    {
        $this->user = null;
    }
}
```

### 2. `app/Http/Middleware/SetUserContext.php`

Middleware that sets `UserContext` from the authenticated user for all HTTP requests.

```php
<?php

namespace App\Http\Middleware;

use App\Services\UserContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserContext
{
    public function __construct(private UserContext $userContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $this->userContext->set($request->user());
        }

        return $next($request);
    }
}
```

---

## Files to Modify

### Phase 1 — Create `UserContext` and `SetUserContext` middleware, update `AppServiceProvider`, register middleware

#### CREATE `app/Services/UserContext.php`

(see above)

#### CREATE `app/Http/Middleware/SetUserContext.php`

(see above)

#### MODIFY `app/Providers/AppServiceProvider.php`

- **Remove** the entire `DaftraApiClient` binding (lines 27-34):
  ```php
  $this->app->bind(DaftraApiClient::class, function ($app) {
      $user = \Context::get('user') ?? auth()->user();
      ...
  });
  ```
- **Remove** the entire `FoodicsApiClient` binding (lines 36-43):
  ```php
  $this->app->bind(FoodicsApiClient::class, function ($app) {
      $user = \Context::get('user');
      ...
  });
  ```
- **Add** `UserContext` scoped registration in `register()`:
  ```php
  $this->app->scoped(UserContext::class);
  ```
- **Remove** all `\Context::get('user')` references
- **Remove** `use App\Services\Daftra\DaftraApiClient;`
- **Remove** `use App\Services\Foodics\FoodicsApiClient;`
- **Remove** `use Illuminate\Support\Facades\Context;` (if present; currently accessed via `\Context`)
- **Add** `use App\Services\UserContext;`
- **Keep** the existing `View::composer` and `Response::macro` in `boot()` unchanged

No custom bindings for `DaftraApiClient` or `FoodicsApiClient` are needed — they now depend on `UserContext` and will be auto-resolved by the container.

#### MODIFY `bootstrap/app.php`

- **Add** `SetUserContext` middleware to the web and api middleware groups:
  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->appendToGroup('web', [
          \App\Http\Middleware\SetUserContext::class,
      ]);
      $middleware->appendToGroup('api', [
          \App\Http\Middleware\SetUserContext::class,
      ]);
  })
  ```
  Or prepend depending on whether other middleware needs the user context. It should run after the auth middleware so `auth()->user()` is available.

---

### Phase 2 — API client classes (replace `User $user` constructor param with `UserContext`)

#### MODIFY `app/Services/Daftra/DaftraApiClient.php`

- **Replace** constructor: `__construct(User $user)` → `__construct(protected UserContext $userContext)`
- **Replace** `$this->user` references with `$this->userContext->get()` throughout:
  - `$this->user->setting(...)` → `$this->userContext->get()->setting(...)`
  - `$this->user->daftra_id` → `$this->userContext->get()->daftra_id`
  - `$this->user->getDaftraToken()` → `$this->userContext->get()->getDaftraToken()`
  - `$this->user->id` → `$this->userContext->id()`
  - `$this->branchId = $user->setting(...)` → `$this->branchId = $this->userContext->get()->setting(...)`
- The `$client` property initialization (which depends on user tokens) must be deferred from the constructor to a lazy initializer, since `UserContext` will be set by the time methods are called but may not be available at construction time. Use a lazy `client()` accessor:
  ```php
  private ?PendingRequest $client = null;

  public function __construct(
      protected UserContext $userContext,
  ) {
      $this->branchId = $userContext->get()->setting(SettingKey::DaftraDefaultBranchId);
  }

  private function client(): PendingRequest
  {
      if ($this->client === null) {
          $user = $this->userContext->get();
          $this->client = \Http::asJson()
              ->acceptJson()
              ->baseUrl(config('services.daftra.base_url'))
              ->withToken($user->getDaftraToken()->token)
              ->withHeaders(['Site-Id' => $user->daftra_id]);
      }
      return $this->client;
  }
  ```
  Then replace all `$this->client` usage with `$this->client()`. The `__call` method already proxies HTTP calls, so update it to use `$this->client()`.
- **Replace** `$this->user` in `refreshToken()` with `$this->userContext->get()`
- **Add** `use App\Services\UserContext;`
- **Keep** `use App\Models\User;` if still used for type hints (no longer needed in constructor)

#### MODIFY `app/Services/Foodics/FoodicsApiClient.php`

- **Replace** constructor: `__construct(protected User $user)` → `__construct(protected UserContext $userContext)`
- **Replace** `$this->user` references with `$this->userContext->get()` throughout:
  - `$this->user->getFoodicsToken()` → `$this->userContext->get()->getFoodicsToken()`
  - `$this->user->id` → `$this->userContext->id()`
- Same lazy initialization pattern as `DaftraApiClient`:
  ```php
  private ?PendingRequest $client = null;

  public function __construct(protected UserContext $userContext)
  {
      //
  }

  private function client(): PendingRequest
  {
      if ($this->client === null) {
          $user = $this->userContext->get();
          $this->client = \Http::asJson()
              ->acceptJson()
              ->baseUrl(config('services.foodics.oauth_url'))
              ->withToken($user->getFoodicsToken()->token);
      }
      return $this->client;
  }
  ```
- **Replace** `$this->client` usage with `$this->client()` in `__call` and `refreshToken`
- **Add** `use App\Services\UserContext;`
- **Remove** `use App\Models\User;` (no longer needed in this file)

---

### Phase 3 — Leaf Daftra services (replace `Context::get('user')->id` with `UserContext`)

These services currently read `Context::get('user')->id`. After this phase they inject `UserContext` via constructor.

#### MODIFY `app/Services/Daftra/ProductService.php`

- **Replace** constructor with: `__construct(protected DaftraApiClient $daftraClient, protected UserContext $userContext)`
- **Replace** `Context::get('user')->id` (line 22 and any other occurrences) with `$this->userContext->id()`
- **Replace** `$this->persistProduct($userId, ...)` calls with `$this->persistProduct($this->userContext->id(), ...)`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/Daftra/ClientService.php`

- **Replace** constructor with: `__construct(protected DaftraApiClient $daftraClient, protected UserContext $userContext)`
- **Replace** `\Context::get('user')->id` (line 122 and any other occurrences) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/Daftra/TaxService.php`

- **Replace** constructor with: `__construct(protected DaftraApiClient $daftraClient, protected UserContext $userContext)`
- **Replace** `Context::get('user')->id` (line 15) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/Daftra/PaymentMethodService.php`

- **Replace** constructor with: `__construct(protected DaftraApiClient $daftraClient, protected UserContext $userContext)`
- **Replace** `Context::get('user')->id` (line 61 and any other occurrences) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

---

### Phase 4 — Foodics services

#### MODIFY `app/Services/Foodics/OrderService.php`

- **Replace** constructor with: `__construct(protected FoodicsApiClient $client, protected UserContext $userContext)`
- **Replace** `Context::get('user')` (line 44) with `$this->userContext->get()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/Foodics/ProductService.php`

- No changes needed. This service only uses `$this->client` — no Context usage.

#### MODIFY `app/Services/Daftra/InvoiceService.php`

- No changes needed. Only uses `$this->daftraClient`, no Context usage.

---

### Phase 5 — Domain sync services (inject `UserContext` instead of Context)

#### MODIFY `app/Services/SyncProductService.php`

- **Add** `protected UserContext $userContext` as second constructor parameter: `__construct(protected DaftraProductService $daftraProductService, protected UserContext $userContext)`
- **Replace** `Context::get('user')?->id` (lines 42, 55) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/Concerns/BuildsInvoiceItems.php`

- **Replace** `Context::get('user')` (line 212) in `resolveDefaultClientId()` with `$this->userContext->get()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`
- Note: This trait is used by `SyncOrder` and `SyncCreditNote`. Both will have `$this->userContext` after Phase 5 changes, so the trait will access it via `$this->userContext`.

#### MODIFY `app/Services/SyncOrder.php`

- **Add** `protected UserContext $userContext` constructor parameter:
  ```php
  public function __construct(
      protected InvoiceService $invoiceService,
      protected ProductService $productService,
      protected ClientService $clientService,
      protected TaxService $taxService,
      protected PaymentMethodService $paymentMethodService,
      protected SyncCreditNote $syncCreditNote,
      protected UserContext $userContext,
  ) {}
  ```
- **Replace** `Context::get('user')?->id` (lines 195, 219) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Services/SyncCreditNote.php`

- **Add** `protected UserContext $userContext` constructor parameter:
  ```php
  public function __construct(
      protected InvoiceService $invoiceService,
      protected ProductService $productService,
      protected ClientService $clientService,
      protected TaxService $taxService,
      protected UserContext $userContext,
  ) {}
  ```
- **Replace** `Context::get('user')?->id` (lines 41, 129) with `$this->userContext->id()`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

---

### Phase 6 — Entry points (replace `Context::add('user', ...)` with `UserContext::set()`)

Each entry point replaces `Context::add('user', $user)` with `app(UserContext::class)->set($user)` as the **first action in `handle()`**. After that, `app(SomeService::class)` resolves the full graph automatically — no factory needed.

**Important**: `app(UserContext::class)->set($this->user)` must happen before any service resolution.

#### MODIFY `app/Jobs/SyncProductsJob.php`

Current:
```php
public function handle(): void
{
    try {
        Context::add('user', $this->user);
        if (!$this->user->getFoodicsToken()) {
```

After:
```php
public function handle(): void
{
    app(UserContext::class)->set($this->user);

    try {
        if (!$this->user->getFoodicsToken()) {
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Jobs/SyncInvoicesJob.php`

Current:
```php
public function handle(): void
{
    try {
        Context::add('user', $this->user);
        if (!$this->user->getFoodicsToken()) {
```

After:
```php
public function handle(): void
{
    app(UserContext::class)->set($this->user);

    try {
        if (!$this->user->getFoodicsToken()) {
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Jobs/RetryProductSyncJob.php`

Current:
```php
$user = $this->product->user;
Context::add('user', $user);
```

After:
```php
$user = $this->product->user;
app(UserContext::class)->set($user);
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Jobs/RetryInvoiceSyncJob.php`

Current:
```php
$user = $this->invoice->user;
Context::add('user', $user);
```

After:
```php
$user = $this->invoice->user;
app(UserContext::class)->set($user);
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Jobs/ProcessWebhookLogJob.php`

Current:
```php
if ($webhookLog->user) {
    Context::add('user', $webhookLog->user);
}
```

After:
```php
if ($webhookLog->user) {
    app(UserContext::class)->set($webhookLog->user);
}
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Webhooks/Handlers/OrderCreatedHandler.php`

Current:
```php
Context::add('user', $user);
$order = $this->resolveOrderService($user)->getOrder($orderId);
app(SyncOrder::class)->handle($order);
```

After:
```php
app(UserContext::class)->set($user);
$order = app(OrderService::class)->getOrder($orderId);
app(SyncOrder::class)->handle($order);
```

- **Remove** `$this->resolveOrderService()` method — no longer needed since the container auto-wires `OrderService` with `UserContext`
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Remove** `use App\Services\Foodics\FoodicsApiClient;` (only used by `resolveOrderService`)
- **Replace** `use App\Services\Foodics\OrderService;` if needed for `app(OrderService::class)`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Webhooks/Handlers/OrderCancelledHandler.php`

Same pattern as OrderCreatedHandler:

Current:
```php
Context::add('user', $user);
$order = $this->resolveOrderService($user)->getOrder($orderId);
app(SyncOrder::class)->handle($order);
```

After:
```php
app(UserContext::class)->set($user);
$order = app(OrderService::class)->getOrder($orderId);
app(SyncOrder::class)->handle($order);
```

- **Remove** `$this->resolveOrderService()` method
- **Remove** `use Illuminate\Support\Facades\Context;`
- **Remove** `use App\Services\Foodics\FoodicsApiClient;` (only used by `resolveOrderService`)
- **Replace** `use App\Services\Foodics\OrderService;` if needed for `app(OrderService::class)`
- **Add** `use App\Services\UserContext;`

#### MODIFY `app/Console/Commands/SyncOrdersCommand.php`

Current:
```php
Context::add('user', $user);
```

After:
```php
app(UserContext::class)->set($user);
```

- **Remove** `use Illuminate\Support\Facades\Context;`
- **Add** `use App\Services\UserContext;`

---

### Phase 7 — DashboardService + Controller cleanup

#### MODIFY `app/Services/DashboardService.php`

Current (in `getDefaultSettings`):
```php
$clientService = app(ClientService::class);
```

After:
```php
// UserContext is already set by SetUserContext middleware for HTTP requests
$clientService = app(ClientService::class);
```

No changes needed — `SetUserContext` middleware handles setting the user for HTTP requests. The `ClientService` is now auto-wired with `UserContext`.

- **Remove** `use RuntimeException;` if no longer needed (verify other methods still use it)
- No import changes needed — `DashboardService` doesn't use Context directly

---

### Phase 8 — Test files

Every test that currently does `Context::add('user', $this->user)` must be updated. The general pattern:

**Before:**
```php
Context::add('user', $this->user);
$result = app(SomeService::class)->someMethod();
```

**After:**
```php
app(UserContext::class)->set($this->user);
$result = app(SomeService::class)->someMethod();
```

For tests that mock API clients via `$this->instance()` or `$this->mock()`, the mock binding replaces the container resolution, so `UserContext` still needs to be set but the mocked client takes precedence.

For tests that directly construct services with `new DaftraApiClient($user)`, those need to be updated to `new DaftraApiClient(app(UserContext::class))` since the constructor now takes `UserContext` instead of `User`.

#### Test files requiring update:

| File | Change |
|------|--------|
| `tests/Feature/WebhookOrderCancelledTest.php` | Replace `Context::add/forget/get` with `UserContext::set/get`, remove Context import |
| `tests/Feature/WebhookOrderCreatedTest.php` | Same |
| `tests/Unit/ClientServiceTest.php` | Replace `Context::add('user', $this->user)` with `app(UserContext::class)->set($this->user)` |
| `tests/Feature/DaftraApiClientTest.php` | Replace Context + update `new DaftraApiClient($user)` to `new DaftraApiClient($userContext)` or use `app()` |
| `tests/Feature/Services/SyncOrderReturnTest.php` | Same pattern |
| `tests/Feature/Services/SyncOrderTest.php` | Same pattern |
| `tests/Feature/Services/SyncOrderTaxTest.php` | Same pattern |
| `tests/Feature/Services/Foodics/OrderServiceTest.php` | Same pattern |
| `tests/Feature/Services/FoodicsReferenceTest.php` | Same pattern |
| `tests/Feature/Services/TaxServiceTest.php` | Same pattern |
| `tests/Feature/Services/PaymentMethodServiceTest.php` | Same pattern |
| `tests/Feature/Services/Daftra/InvoiceServiceTest.php` | Same pattern |
| `tests/Feature/Services/Daftra/ProductServiceTest.php` | Same pattern |
| `tests/Feature/Services/Daftra/TaxServiceTest.php` | Same pattern |
| `tests/Feature/ProductSyncTest.php` | Same pattern |
| `tests/Feature/RetryProductSyncTest.php` | Same pattern |
| `tests/Feature/RetryInvoiceSyncTest.php` | Same pattern |
| `tests/Feature/Services/SyncOrder/WalkInDefaultClientTest.php` | Same pattern |
| `tests/Feature/Services/SyncOrder/InvoiceLifecycleTest.php` | Same pattern |
| `tests/Feature/Services/Foodics/FoodicsApiClientTest.php` | Same pattern |
| `tests/Feature/Services/Foodics/ProductServiceTest.php` | Same pattern |

#### `tests/Feature/WebhookOrderCancelledTest.php`

- **Replace** `Context::get('user')` assertions (lines 148, 196, 197) with `app(UserContext::class)->get()` assertions
- **Replace** `Context::forget('user')` (line 201) with `app(UserContext::class)->flush()`
- **Replace** `use Illuminate\Support\Facades\Context;` with `use App\Services\UserContext;`

#### `tests/Feature/WebhookOrderCreatedTest.php`

- **Replace** `Context::get('user')->id` assertions (lines 219, 255) with `app(UserContext::class)->get()->id`
- **Replace** `Context::forget('user')` (line 349) with `app(UserContext::class)->flush()`
- **Replace** `use Illuminate\Support\Facades\Context;` with `use App\Services\UserContext;`

---

### Phase 9 — Cleanup

1. **Search entire codebase** for remaining `Context::` usages and remove them
2. **Search entire codebase** for remaining `use Illuminate\Support\Facades\Context;` imports and remove them
3. **Verify** no `app(DaftraApiClient::class)` or `app(FoodicsApiClient::class)` calls exist outside `AppServiceProvider` — they should be resolved through DI or `app()` with UserContext auto-wired
4. **Run** `vendor/bin/pint --dirty --format agent` to format changed files
5. **Run** `php artisan test --compact` to verify all tests pass

---

## Files NOT Changed

These files don't use Context and don't need modification:

- `app/Services/Daftra/InvoiceService.php` — only uses `$this->daftraClient`, no Context
- `app/Services/Foodics/ProductService.php` — only uses `$this->client`, no Context
- `app/Http/Controllers/*` — controllers use `auth()->user()` directly, no Context
- `app/Models/*` — no Context usage

---

## Summary Count

| Category | Count |
|----------|-------|
| New files | 2 (`UserContext.php`, `SetUserContext.php`) |
| Modified API client files | 2 (`DaftraApiClient.php`, `FoodicsApiClient.php`) |
| Modified service files | 8 |
| Modified entry point files | 8 |
| Modified provider files | 1 (`AppServiceProvider.php`) |
| Modified bootstrap files | 1 (`bootstrap/app.php`) |
| Modified test files | ~22 |
| **Total** | ~44 file touches |

---

## How This Is Different from Context

| Aspect | Context | UserContext (this approach) |
|--------|---------|----------------------------|
| Type safety | `Context::get('user')` returns `mixed` | `UserContext::get()` returns `User` |
| Discovery | Hidden — any file can read Context | Explicit — visible in constructor signatures |
| Container wiring | Manual (`app(Service::class)` doesn't know about Context) | Automatic (container resolves `UserContext` dependency) |
| Octane safety | Must remember to flush | `scoped` binding is flushed automatically |
| Testability | Must set Context before each test | Just `app(UserContext::class)->set($user)` — same surface area but typed |
| API client resolution | Manual binding with Context fallback | Auto-wired — `DaftraApiClient(UserContext)` resolved by container |
| HTTP auth | `auth()->user()` fallback in service provider | `SetUserContext` middleware sets it once |
| Job handling | Same `Context::add('user', $user)` pattern | Same pattern with `UserContext::set($this->user)` — must be first line in `handle()` |

## Key Difference from Factory Approach

The factory approach (Variant B) required a `UserServices` class with 13+ methods that manually wired every service. This approach lets the container do that wiring — we only add two classes (`UserContext`, `SetUserContext`) and change constructor signatures. The container resolves `app(SyncOrder::class)` by auto-wiring `UserContext` into every service in the dependency graph, including `DaftraApiClient` and `FoodicsApiClient`.

---

## Risk Mitigation

1. **Constructor signature changes** — Services now accept `UserContext` instead of nothing. The container auto-resolves since `UserContext` is `scoped`. Any test that constructs services manually must now pass `UserContext` (or use `app()` which auto-resolves).

2. **`BuildsInvoiceItems` trait** — Both `SyncOrder` and `SyncCreditNote` will have `$this->userContext` after adding `UserContext` to their constructors. The trait will access `$this->userContext->get()` instead of `Context::get('user')`.

3. **Test mocking** — Tests that mock `DaftraApiClient` via `$this->instance()` or `$this->mock()` still work. The mock replaces the container binding, and `UserContext` is set independently. Tests just need to replace `Context::add('user', ...)` with `app(UserContext::class)->set(...)`.

4. **Octane safety** — `UserContext` is registered as `scoped`. Laravel Octane flushes all scoped bindings between worker requests. For queue workers, each job lifecycle gets fresh scoped instances because `handle()` runs in a fresh application lifecycle.

5. **HTTP requests** — The `SetUserContext` middleware sets `UserContext` from `auth()->user()` before any controller code runs. This replaces the previous `\Context::get('user') ?? auth()->user()` fallback.

6. **Job serialization** — Jobs already carry `$this->user` (serialized with the job). The job sets `UserContext` as the first line in `handle()`. Since `UserContext` is scoped, each job gets a fresh instance. The ordering constraint (set UserContext before resolving services) is identical to the current `Context::add('user', $user)` ordering constraint — not a new risk.

7. **Lazy API client initialization** — `DaftraApiClient` and `FoodicsApiClient` now use lazy client initialization (the HTTP client is built on first use, not in the constructor). This ensures `UserContext::get()` is called when the client is actually used, not when the container constructs it.

8. **Dependency injection over `app()`** — Entry points (jobs, commands, handlers) use `app(UserContext::class)->set($user)` as the initial setup, and `app(Service::class)` to resolve the service graph. Inside services, all dependencies are injected via constructors. This avoids hidden `app()` calls inside service methods, keeping the dependency graph explicit and testable.