# Laravel Octane with Swoole Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Laravel Octane with the Swoole driver to supercharge request throughput via long-running workers.

**Architecture:** Install `laravel/octane`, publish its config, audit the codebase for statefulness issues, and fix any mutable service state that would leak across requests within a worker. Swoole is provided by the Docker image from spec/036 — no local Swoole installation required. The app already uses database-backed sessions, cache, and queues — all compatible with Octane.

**Tech Stack:** Laravel 13, PHP 8.5, Swoole (via Docker), Laravel Octane

**Prerequisite:** spec/036-dockerize-with-swoole.md must be completed first.

---

## Codebase Statefulness Audit Findings

| Area | Status | Notes |
|---|---|---|
| `SESSION_DRIVER=database` | ✅ Compatible | No file-based sessions |
| `CACHE_STORE=database` | ✅ Compatible | `octane` cache store already exists in `config/cache.php` |
| `QUEUE_CONNECTION=database` | ✅ Compatible | No in-memory queues |
| `UserContext` (scoped binding) | ✅ Compatible | Registered as `scoped` in `AppServiceProvider`, rebuilt per request by Octane |
| `DaftraApiClient` / `FoodicsApiClient` | ⚠️ Needs fix | Auto-resolved (not explicitly bound), hold mutable `$client` property — must be registered as scoped |
| `View::composer` in `AppServiceProvider::boot()` | ✅ Compatible | Reads from `request()` which is per-request |
| Static mutable state | ✅ None found | No `static $` properties or globals in `app/` |
| `SetUserContext` middleware | ✅ Compatible | Scoped binding ensures fresh `UserContext` per request |

## File Structure

| Action | File | Responsibility |
|---|---|---|
| Modify | `composer.json` | Add `laravel/octane` dependency |
| Create | `config/octane.php` | Octane configuration (workers, max requests, task workers) |
| Modify | `app/Providers/AppServiceProvider.php` | Register `DaftraApiClient` and `FoodicsApiClient` as scoped bindings |
| Modify | `.env.example` | Add `OCTANE_*` environment variables |
| Modify | `.env.docker` | Add `OCTANE_*` environment variables for Docker |
| Modify | `docker-compose.yml` | Add `OCTANE_*` env vars to app service |
| Create | `tests/Feature/OctaneUserContextIsolationTest.php` | Test that API clients are scoped per request |
| Create | `tests/Feature/OctaneBootTest.php` | Test that the app boots correctly under Octane |

---

### Task 1: Install Laravel Octane

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Require laravel/octane via Composer**

Run:
```bash
composer require laravel/octane
```

Expected: Package installed successfully, `composer.json` and `composer.lock` updated.

- [ ] **Step 2: Verify installation**

Run:
```bash
php artisan octane:status
```

Expected: Command is available (may show "Octane is not running" — that's fine).

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: install laravel/octane"
```

---

### Task 2: Publish Octane Configuration

**Files:**
- Create: `config/octane.php`

- [ ] **Step 1: Publish the Octane config file**

Run:
```bash
php artisan vendor:publish --tag=octane-config --no-interaction
```

Expected: `config/octane.php` created.

- [ ] **Step 2: Read the generated config and customize defaults**

Read the published `config/octane.php` and replace its contents with:

```php
<?php

use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Listeners\WarmConfigCache;

return [

    'server' => env('OCTANE_SERVER', 'swoole'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            WarmConfigCache::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        WorkerErrorOccurred::class => [
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        //
    ],

    'swoole' => [
        'max_request' => (int) env('OCTANE_MAX_REQUESTS', 500),
        'task_worker_num' => (int) env('OCTANE_TASK_WORKERS', 0),
        'task_max_request' => (int) env('OCTANE_TASK_MAX_REQUESTS', 500),
    ],

    'cache' => [
        'rows' => (int) env('OCTANE_CACHE_ROWS', 1000),
        'bytes' => (int) env('OCTANE_CACHE_BYTES', 10000),
    ],

    'tick_interval' => (int) env('OCTANE_TICK_INTERVAL', 5),

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'resources/views',
        'routes',
    ],

    'garbage_collection_threshold' => (int) env('OCTANE_GC_THRESHOLD', 50),

];
```

Key decisions:
- Removed unused imports (`FrankenPhpClient`, `SwooleClient`, `FlushTemporaryContainerInstances`, `FlushUploadedFiles`) that the default config includes but we don't need.
- Kept `DisconnectFromDatabases` in `RequestTerminated` — important for long-running workers to avoid stale connections.
- `OCTANE_SERVER` defaults to `swoole`, matching the Docker image.

- [ ] **Step 3: Commit**

```bash
git add config/octane.php
git commit -m "feat: publish and customize octane config for swoole"
```

---

### Task 3: Register API Clients as Scoped Bindings

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/OctaneUserContextIsolationTest.php`

Both `DaftraApiClient` and `FoodicsApiClient` hold mutable state (`$client` property that caches a `PendingRequest` instance). In a long-running Octane worker, explicitly registering them as `scoped` guarantees a fresh instance per request, matching the `UserContext` lifecycle they depend on.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OctaneUserContextIsolationTest.php`:

```php
<?php

use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\UserContext;

it('resolves UserContext as scoped binding', function () {
    $first = app(UserContext::class);
    $second = app(UserContext::class);

    expect($first)->toBe($second);
});

it('resolves DaftraApiClient as scoped binding', function () {
    $user = User::factory()->create();
    $user->providerTokens()->create([
        'provider' => 'daftra',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addDay(),
    ]);

    app(UserContext::class)->set($user);

    $first = app(DaftraApiClient::class);
    $second = app(DaftraApiClient::class);

    expect($first)->toBe($second);
});

it('resolves FoodicsApiClient as scoped binding', function () {
    $user = User::factory()->create();
    $user->providerTokens()->create([
        'provider' => 'foodics',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'expires_at' => now()->addDay(),
    ]);

    app(UserContext::class)->set($user);

    $first = app(FoodicsApiClient::class);
    $second = app(FoodicsApiClient::class);

    expect($first)->toBe($second);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
php artisan test --compact tests/Feature/OctaneUserContextIsolationTest.php
```

Expected: FAIL — `DaftraApiClient` and `FoodicsApiClient` are not explicitly bound, so auto-resolution creates new instances each time.

- [ ] **Step 3: Register API clients as scoped in AppServiceProvider**

Update `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Http\CurlCommandBuilder;
use App\Services\UserContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(UserContext::class);
        $this->app->scoped(DaftraApiClient::class);
        $this->app->scoped(FoodicsApiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Response::macro('toCurl', function (): string {
            /** @var Response $this */
            $request = $this->transferStats?->getRequest();

            return $request ? CurlCommandBuilder::build($request) : '';
        });

        View::composer(['layouts.app', 'login', 'landing'], function ($view) {
            $fontMap = [
                'en' => ['inter' => 'Inter', 'instrument-sans' => 'Instrument Sans', 'noto' => 'Noto Sans'],
                'ar' => ['cairo' => 'Cairo', 'ibm-plex-arabic' => 'IBM Plex Sans Arabic', 'noto-arabic' => 'Noto Sans Arabic'],
            ];
            $view->with([
                'enFont' => $fontMap['en'][request()->get('en_font')] ?? 'Noto Sans',
                'arFont' => $fontMap['ar'][request()->get('ar_font')] ?? 'Noto Sans Arabic',
            ]);
        });
    }
}
```

Key change: Added `$this->app->scoped(DaftraApiClient::class)` and `$this->app->scoped(FoodicsApiClient::class)`. Scoped bindings are rebuilt per request in Octane, ensuring no stale HTTP client state leaks between requests.

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
php artisan test --compact tests/Feature/OctaneUserContextIsolationTest.php
```

Expected: PASS — all three bindings resolve as the same instance within a request scope.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/OctaneUserContextIsolationTest.php
git commit -m "feat: register API clients as scoped bindings for Octane compatibility"
```

---

### Task 4: Update Environment Configuration

**Files:**
- Modify: `.env.example`
- Modify: `.env.docker`

- [ ] **Step 1: Add Octane environment variables to `.env.example`**

Append the following to `.env.example` after the `FOODICS_REDIRECT_URI=` block added by spec/036:

```
OCTANE_SERVER=swoole
OCTANE_MAX_REQUESTS=500
OCTANE_TASK_WORKERS=0
```

- [ ] **Step 2: Add Octane environment variables to `.env.docker`**

Append the following to `.env.docker` after the `VITE_APP_NAME` line:

```
OCTANE_SERVER=swoole
OCTANE_MAX_REQUESTS=500
OCTANE_TASK_WORKERS=0
```

- [ ] **Step 3: Commit**

```bash
git add .env.example .env.docker
git commit -m "feat: add octane environment variables to env files"
```

---

### Task 5: Update Docker Compose with Octane Environment Variables

**Files:**
- Modify: `docker-compose.yml`

The `docker-compose.yml` from spec/036 needs `OCTANE_*` variables passed to the `app` and `queue` services so Octane reads them at runtime.

- [ ] **Step 1: Add OCTANE_* env vars to the app service**

In `docker-compose.yml`, add these entries to the `app.service.environment` section, after the `FOODICS_REDIRECT_URI` line:

```yaml
      OCTANE_SERVER: "${OCTANE_SERVER:-swoole}"
      OCTANE_MAX_REQUESTS: "${OCTANE_MAX_REQUESTS:-500}"
      OCTANE_TASK_WORKERS: "${OCTANE_TASK_WORKERS:-0}"
```

- [ ] **Step 2: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: add octane env vars to docker-compose"
```

---

### Task 6: Write Octane Boot Test

**Files:**
- Create: `tests/Feature/OctaneBootTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/OctaneBootTest.php`:

```php
<?php

use Illuminate\Foundation\Application;

it('boots the application successfully', function () {
    $app = app();

    expect($app)->toBeInstanceOf(Application::class);
    expect($app->isBootstrapped())->toBeTrue();
});

it('has octane cache store configured', function () {
    expect(config('cache.stores.octane'))->not->toBeNull();
    expect(config('cache.stores.octane.driver'))->toBe('octane');
});

it('has octane config file', function () {
    expect(config('octane'))->not->toBeNull();
    expect(config('octane.server'))->toBe('swoole');
});
```

- [ ] **Step 2: Run the test to verify it passes**

Run:
```bash
php artisan test --compact tests/Feature/OctaneBootTest.php
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/OctaneBootTest.php
git commit -m "test: add octane boot and configuration tests"
```

---

### Task 7: Rebuild Docker Image and Verify

**Files:**
- None (verification only)

Now that `laravel/octane` is in `composer.json`, the Docker image needs a rebuild. The `CMD` in the Dockerfile (`php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000`) will now work.

- [ ] **Step 1: Rebuild the Docker image**

Run:
```bash
docker compose build app
```

Expected: Build succeeds. Composer installs `laravel/octane` inside the image.

- [ ] **Step 2: Start the stack**

Run:
```bash
docker compose up -d
```

- [ ] **Step 3: Verify Octane is running**

Run:
```bash
docker compose exec app php artisan octane:status
```

Expected: Shows Octane is running with Swoole.

- [ ] **Step 4: Hit the health endpoint**

Run:
```bash
curl http://localhost:8000/up
```

Expected: HTTP 200 response.

---

### Task 8: Run Full Test Suite and Format Code

- [ ] **Step 1: Run Pint to format all changed files**

Run:
```bash
vendor/bin/pint --dirty --format agent
```

Expected: All files formatted.

- [ ] **Step 2: Run the full test suite**

Run:
```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 3: Commit any formatting fixes (if Pint made changes)**

```bash
git add -A
git commit -m "style: format code with pint"
```

(Only if Pint made changes.)

---

## Post-Installation Usage

### Running via Docker (recommended)

```bash
# Start everything
composer docker:up

# Or manually:
docker compose up -d

# Tail logs
docker compose logs -f app
```

The app is available at `http://localhost:8000`. Octane runs inside the container — no local Swoole installation needed.

### Running locally without Docker

This requires Swoole installed locally:

```bash
# Check if Swoole is installed
php -m | grep swoole

# If installed, start Octane:
php artisan octane:start

# With file watcher:
php artisan octane:start --watch
```

### Production tuning

Adjust in `.env` or `docker-compose.yml`:

```
OCTANE_MAX_REQUESTS=1000    # Restart workers after N requests to prevent memory leaks
OCTANE_TASK_WORKERS=2       # Enable task workers for concurrent operations
OCTANE_GC_THRESHOLD=100     # Garbage collection threshold
```
