# Branches and Taxes Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a dedicated `/mappings` page where users can sync Foodics branches and taxes, and map each to a Daftra branch or tax. When Daftra's branches plugin is disabled, all Foodics branches fall back to the default Daftra branch. The sync engine uses per-order branch resolution.

**Architecture:** Create a `Foodics\BranchService` and `Foodics\TaxService` to fetch branches/taxes from Foodics API. Reuse the `entity_mappings` table with `type='branch'` and `type='tax'`. Add a `MappingController` with a dedicated `/mappings` page. Modify `SyncOrder` to resolve the Daftra branch per-order based on the order's Foodics branch. Tax auto-matching continues as-is, but the mapping page also allows manual mapping (both share the same `entity_mappings` rows, so auto-matching finds manual mappings too).

**Tech Stack:** Laravel 12, Pest 4, Blade + Alpine.js, existing `entity_mappings` table

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Services/Foodics/BranchService.php` | Fetch branches from Foodics API (`GET /v5/branches`) |
| `app/Services/Foodics/TaxService.php` | Fetch taxes from Foodics API (`GET /v5/taxes`) and tax groups (`GET /v5/tax_groups`) |
| `app/Http/Controllers/MappingController.php` | Show mapping page, handle sync/mapping POST requests |
| `app/Http/Requests/StoreBranchMappingRequest.php` | Validate branch mapping saves |
| `app/Http/Requests/StoreTaxMappingRequest.php` | Validate tax mapping saves |
| `resources/views/mappings.blade.php` | Mapping page UI with branch and tax sections |
| `resources/views/layouts/app.blade.php` | Add "Mappings" nav link (modify) |
| `routes/web.php` | Add mapping routes (modify) |
| `app/Services/SyncOrder.php` | Add per-order branch resolution (modify) |
| `app/Services/Concerns/BuildsInvoiceItems.php` | Read branch mapping context (modify if needed) |
| `tests/Feature/Services/Foodics/BranchServiceTest.php` | Tests for Foodics branch fetching |
| `tests/Feature/Services/Foodics/FoodicsTaxServiceTest.php` | Tests for Foodics tax fetching |
| `tests/Feature/MappingPageTest.php` | Feature tests for the mapping page |
| `tests/Feature/MappingControllerTest.php` | Feature tests for sync and save endpoints |

### Key design decisions

1. **Storage:** Reuse `entity_mappings` table with `type='branch'` and `type='tax'`. The existing unique constraint `(user_id, type, foodics_id)` already supports this.
2. **Branch detection:** Use existing `DaftraApiClient::tryGetBranches()` — returns `null` when disabled, array when enabled.
3. **Tax auto-matching:** The existing `Daftra\TaxService::resolveTaxId()` already checks `entity_mappings` first (cache hit). Manual mappings on the mapping page write to `entity_mappings` with `type='tax'`, so auto-matching finds them automatically.
4. **Per-order branch resolution:** When syncing an order, `SyncOrder` reads the order's `branch.id`, looks up the branch mapping in `entity_mappings`, and constructs a scoped `DaftraApiClient` for that branch. Falls back to the global default if no mapping exists.
5. **Foodics branch data:** Not stored locally — fetched from Foodics API when the user clicks "Sync Branches" and passed directly to the view.
6. **Foodics tax data:** Not stored locally — fetched from Foodics API when the user clicks "Sync Taxes" and passed directly to the view.

---

## Task 1: Foodics BranchService

**Files:**
- Create: `app/Services/Foodics/BranchService.php`
- Test: `tests/Feature/Services/Foodics/BranchServiceTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Services\Foodics\BranchService;
use App\Services\Foodics\FoodicsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches all branches from Foodics', function () {
    $branchesData = [
        'data' => [
            ['id' => 'branch-1', 'name' => 'Branch 1', 'reference' => 'B01'],
            ['id' => 'branch-2', 'name' => 'Branch 2', 'reference' => 'B02'],
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsResponse($branchesData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(BranchService::class);
    $branches = $service->fetchBranches();

    expect($branches)->toHaveCount(2);
    expect($branches[0]['id'])->toBe('branch-1');
    expect($branches[1]['name'])->toBe('Branch 2');
});

it('returns empty array when no branches exist', function () {
    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(BranchService::class);
    $branches = $service->fetchBranches();

    expect($branches)->toBe([]);
});

it('fetches branches across multiple pages', function () {
    $page1 = ['data' => array_map(fn ($i) => ['id' => "branch-$i", 'name' => "Branch $i", 'reference' => "B0$i"], range(1, 50))];
    $page2 = ['data' => [['id' => 'branch-51', 'name' => 'Branch 51', 'reference' => 'B051']]];
    $page3 = ['data' => []];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::on(fn ($p) => ! isset($p['after'])))
        ->once()
        ->andReturn(fakeFoodicsResponse($page1, 'cursor-page-2'));
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::on(fn ($p) => ($p['after'] ?? null) === 'cursor-page-2'))
        ->once()
        ->andReturn(fakeFoodicsResponse($page2, null));
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::on(fn ($p) => ($p['after'] ?? null) === null && isset($p['_paged'])))
        ->never();

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(BranchService::class);
    $branches = $service->fetchBranches();

    expect($branches)->toHaveCount(51);
});

function fakeFoodicsResponse(array $json, ?string $nextCursor = null): object
{
    if ($nextCursor !== null) {
        $json['meta'] = ['next_cursor' => $nextCursor];
    }

    return new class($json)
    {
        public function __construct(private array $json) {}

        public function successful(): bool
        {
            return true;
        }

        public function failed(): bool
        {
            return false;
        }

        public function throw(): static
        {
            return $this;
        }

        public function json($key = null, $default = null): mixed
        {
            if ($key === null) {
                return $this->json;
            }

            return data_get($this->json, $key, $default);
        }
    };
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Foodics/BranchServiceTest.php`
Expected: FAIL — `Class "App\Services\Foodics\BranchService" does not exist`

- [x] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Foodics;

class BranchService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchBranches(): array
    {
        $allBranches = [];
        $after = null;
        $hasMore = true;

        while ($hasMore) {
            $params = ['limit' => 50];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->client->get('/v5/branches', $params);
            $response->throw();

            $data = $response->json('data') ?? [];

            if (empty($data)) {
                $hasMore = false;

                continue;
            }

            $allBranches = array_merge($allBranches, $data);

            $meta = $response->json('meta') ?? [];
            $cursor = $meta['next_cursor'] ?? null;

            if ($cursor !== null) {
                $after = $cursor;
            } else {
                $hasMore = false;
            }
        }

        return $allBranches;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Foodics/BranchServiceTest.php`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add app/Services/Foodics/BranchService.php tests/Feature/Services/Foodics/BranchServiceTest.php
git commit -m "feat: add Foodics BranchService for fetching branches via cursor pagination"
```

---

## Task 2: Foodics TaxService

**Files:**
- Create: `app/Services/Foodics/TaxService.php`
- Test: `tests/Feature/Services/Foodics/FoodicsTaxServiceTest.php`

This service fetches individual taxes (not tax groups) from Foodics. Tax groups are only relevant for branches; the mapping page maps individual taxes.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Services\Foodics\TaxService;
use App\Services\Foodics\FoodicsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('fetches all taxes from Foodics', function () {
    $taxesData = [
        'data' => [
            ['id' => 'tax-1', 'name' => 'VAT', 'rate' => 15],
            ['id' => 'tax-2', 'name' => 'VAT', 'rate' => 5],
        ],
    ];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsResponse($taxesData));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $taxes = $service->fetchTaxes();

    expect($taxes)->toHaveCount(2);
    expect($taxes[0]['id'])->toBe('tax-1');
    expect($taxes[1]['rate'])->toBe(5);
});

it('returns empty array when no taxes exist', function () {
    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(fakeFoodicsResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    expect($service->fetchTaxes())->toBe([]);
});

it('fetches taxes across multiple pages using cursor pagination', function () {
    $page1 = ['data' => array_map(fn ($i) => ['id' => "tax-$i", 'name' => "Tax $i", 'rate' => $i], range(1, 50))];
    $page2 = ['data' => [['id' => 'tax-51', 'name' => 'Tax 51', 'rate' => 51]]];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::on(fn ($p) => ! isset($p['after'])))
        ->once()
        ->andReturn(fakeFoodicsResponse($page1, 'cursor-page-2'));
    $mockClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::on(fn ($p) => ($p['after'] ?? null) === 'cursor-page-2'))
        ->once()
        ->andReturn(fakeFoodicsResponse($page2, null));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $taxes = $service->fetchTaxes();

    expect($taxes)->toHaveCount(51);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Foodics/FoodicsTaxServiceTest.php`
Expected: FAIL — `Class "App\Services\Foodics\TaxService" does not exist`

- [x] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Foodics;

class TaxService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchTaxes(): array
    {
        $allTaxes = [];
        $after = null;
        $hasMore = true;

        while ($hasMore) {
            $params = ['limit' => 50];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->client->get('/v5/taxes', $params);
            $response->throw();

            $data = $response->json('data') ?? [];

            if (empty($data)) {
                $hasMore = false;

                continue;
            }

            $allTaxes = array_merge($allTaxes, $data);

            $meta = $response->json('meta') ?? [];
            $cursor = $meta['next_cursor'] ?? null;

            if ($cursor !== null) {
                $after = $cursor;
            } else {
                $hasMore = false;
            }
        }

        return $allTaxes;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Foodics/FoodicsTaxServiceTest.php`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add app/Services/Foodics/TaxService.php tests/Feature/Services/Foodics/FoodicsTaxServiceTest.php
git commit -m "feat: add Foodics TaxService for fetching taxes via cursor pagination"
```

---

## Task 3: Daftra Branch Service (list Daftra branches for mapping)

**Files:**
- Modify: `app/Services/Daftra/DaftraApiClient.php` (already has `getBranches()` and `tryGetBranches()`)
- Test: `tests/Feature/MappingControllerTest.php` (covered in Task 5)

No new code needed. `DaftraApiClient::getBranches()` already returns `data` as an array of `Branch` objects. `tryGetBranches()` returns `null` when disabled. We'll use these directly in the controller.

---

## Task 4: Daftra Tax List Endpoint

**Files:**
- Modify: `app/Services/Daftra/TaxService.php` (add `listTaxes()` method)
- Test: Add tests to `tests/Feature/Services/Daftra/TaxServiceTest.php`

The mapping page needs to show all Daftra taxes so the user can pick one to map to. The existing `getTax()` method only searches by name; we need a new `listTaxes()` that returns all taxes.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Services/Daftra/TaxServiceTest.php`:

```php
it('lists all Daftra taxes', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::on(fn (array $args) => isset($args['limit']) && $args['limit'] === 100))
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 1, 'name' => 'VAT', 'value' => 15]],
                ['Tax' => ['id' => 2, 'name' => 'VAT', 'value' => 5]],
            ],
        ]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    $taxes = $service->listTaxes();

    expect($taxes)->toHaveCount(2);
    expect($taxes[0])->toBe(['id' => 1, 'name' => 'VAT', 'value' => 15.0]);
    expect($taxes[1])->toBe(['id' => 2, 'name' => 'VAT', 'value' => 5.0]);
});

it('returns empty array when Daftra has no taxes', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: ['data' => []]));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    expect($service->listTaxes())->toBe([]);
});

it('throws when Daftra tax list request fails', function () {
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: false, status: 500, json: ['error' => 'Server error']));

    $this->app->instance(DaftraApiClient::class, $mockClient);

    $service = $this->app->make(TaxService::class);
    expect(fn () => $service->listTaxes())->toThrow(\RuntimeException::class);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Daftra/TaxServiceTest.php --filter=listTaxes`
Expected: FAIL — `Method App\Services\Daftra\TaxService::listTaxes does not exist`

- [x] **Step 3: Write minimal implementation**

Add to `app/Services/Daftra/TaxService.php`:

```php
/**
 * @return array<int, array{id: int, name: string, value: float}>
 */
public function listTaxes(): array
{
    $response = $this->daftraClient->get('/api2/taxes.json', [
        'limit' => 100,
    ]);

    if (! $response->successful()) {
        throw new \RuntimeException(
            'Daftra tax list request failed: HTTP '.$response->status().' '.$response->body()
        );
    }

    $rows = $response->json('data') ?? [];

    return array_map(function (array $row): array {
        $tax = $row['Tax'] ?? [];

        return [
            'id' => (int) ($tax['id'] ?? 0),
            'name' => (string) ($tax['name'] ?? ''),
            'value' => (float) ($tax['value'] ?? 0),
        ];
    }, $rows);
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Daftra/TaxServiceTest.php`
Expected: PASS (all tests, including existing ones)

- [x] **Step 5: Commit**

```bash
git add app/Services/Daftra/TaxService.php tests/Feature/Services/Daftra/TaxServiceTest.php
git commit -m "feat: add listTaxes method to Daftra TaxService for mapping page"
```

---

## Task 5: MappingController and Routes

**Files:**
- Create: `app/Http/Controllers/MappingController.php`
- Create: `app/Http/Requests/StoreBranchMappingRequest.php`
- Create: `app/Http/Requests/StoreTaxMappingRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MappingControllerTest.php`

The controller has these actions:
- `index` — show the mapping page (loads existing mappings from `entity_mappings`)
- `syncBranches` — fetch branches from Foodics + Daftra, store in session flash, redirect back
- `syncTaxes` — fetch taxes from Foodics + Daftra, store in session flash, redirect back
- `storeBranchMapping` — save branch mappings to `entity_mappings`
- `storeTaxMapping` — save tax mappings to `entity_mappings`

The session flash approach lets us avoid a new table. Foodics/Daftra data is fetched on demand and flashed to the session for the form to display.

- [x] **Step 1: Write the failing test for the controller**

```php
<?php

use App\Enums\SettingKey;
use App\Models\EntityMapping;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\BranchService;
use App\Services\Foodics\TaxService as FoodicsTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'foodics',
        'token' => 'fake-foodics-token',
        'refresh_token' => 'fake-foodics-refresh',
        'expires_at' => now()->addHour(),
    ]);
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'daftra',
        'token' => 'fake-daftra-token',
        'refresh_token' => 'fake-daftra-refresh',
        'expires_at' => now()->addHour(),
    ]);
});

it('shows the mapping page', function () {
    $this->actingAs($this->user)
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Mappings')
        ->assertSee('Sync Branches')
        ->assertSee('Sync Taxes');
});

it('redirects unauthenticated users from GET /mappings', function () {
    $this->get('/mappings')->assertRedirect('/login');
});

it('syncs branches from Foodics and Daftra', function () {
    $mockFoodicsClient = Mockery::mock(\App\Services\Foodics\FoodicsApiClient::class);
    $mockFoodicsClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::any())
        ->once()
        ->andReturn(new class
        {
            public function successful() { return true; }
            public function failed() { return false; }
            public function throw() { return $this; }
            public function json($key = null, $default = null) {
                $data = [
                    'data' => [
                        ['id' => 'fb-1', 'name' => 'Foodics Branch 1', 'reference' => 'B01'],
                    ],
                ];
                return $key === null ? $data : data_get($data, $key, $default);
            }
        });
    $this->app->instance(\App\Services\Foodics\FoodicsApiClient::class, $mockFoodicsClient);

    $mockDaftraClient = Mockery::mock(DaftraApiClient::class);
    $mockDaftraClient->shouldReceive('tryGetBranches')
        ->once()
        ->andReturn([['id' => 1, 'name' => 'Main Branch']]);
    $this->app->instance(DaftraApiClient::class, $mockDaftraClient);

    $this->actingAs($this->user)
        ->post('/mappings/branches/sync')
        ->assertRedirect('/mappings');
});

it('syncs taxes from Foodics and Daftra', function () {
    $mockFoodicsClient = Mockery::mock(\App\Services\Foodics\FoodicsApiClient::class);
    $mockFoodicsClient->shouldReceive('get')
        ->with('/v5/taxes', Mockery::any())
        ->once()
        ->andReturn(new class
        {
            public function successful() { return true; }
            public function failed() { return false; }
            public function throw() { return $this; }
            public function json($key = null, $default = null) {
                $data = [
                    'data' => [
                        ['id' => 'ft-1', 'name' => 'VAT', 'rate' => 15],
                    ],
                ];
                return $key === null ? $data : data_get($data, $key, $default);
            }
        });
    $this->app->instance(\App\Services\Foodics\FoodicsApiClient::class, $mockFoodicsClient);

    $mockDaftraClient = Mockery::mock(DaftraApiClient::class);
    $mockDaftraClient->shouldReceive('get')
        ->with('/api2/taxes.json', Mockery::any())
        ->once()
        ->andReturn(createMockHttpResponse(successful: true, status: 200, json: [
            'data' => [
                ['Tax' => ['id' => 10, 'name' => 'VAT', 'value' => 15]],
            ],
        ]));
    $this->app->instance(DaftraApiClient::class, $mockDaftraClient);

    $this->actingAs($this->user)
        ->post('/mappings/taxes/sync')
        ->assertRedirect('/mappings');
});

it('stores branch mappings', function () {
    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => '2'],
                ['foodics_id' => 'fb-2', 'daftra_id' => ''],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->count())->toBe(1);

    $mapping = EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->first();
    expect($mapping->foodics_id)->toBe('fb-1');
    expect($mapping->daftra_id)->toBe(2);
    expect($mapping->status)->toBe('synced');
});

it('stores tax mappings', function () {
    $this->actingAs($this->user)
        ->post('/mappings/taxes', [
            'mappings' => [
                ['foodics_id' => 'ft-1', 'daftra_id' => '10'],
            ],
        ])
        ->assertRedirect('/mappings');

    $mapping = EntityMapping::where('user_id', $this->user->id)
        ->where('type', 'tax')
        ->where('foodics_id', 'ft-1')
        ->first();

    expect($mapping)->not->toBeNull();
    expect($mapping->daftra_id)->toBe(10);
});

it('updates an existing branch mapping on re-save', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'fb-1',
        'daftra_id' => 1,
        'status' => 'synced',
    ]);

    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => '5'],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->count())->toBe(1);
    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->first()->daftra_id)->toBe(5);
});

it('removes branch mappings when daftra_id is empty', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'fb-1',
        'daftra_id' => 2,
        'status' => 'synced',
    ]);

    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => [
                ['foodics_id' => 'fb-1', 'daftra_id' => ''],
            ],
        ])
        ->assertRedirect('/mappings');

    expect(EntityMapping::where('user_id', $this->user->id)->where('type', 'branch')->exists())->toBeFalse();
});

it('validates branch mapping input', function () {
    $this->actingAs($this->user)
        ->post('/mappings/branches', [
            'mappings' => 'not-an-array',
        ])
        ->assertSessionHasErrors('mappings');
});

it('validates tax mapping input', function () {
    $this->actingAs($this->user)
        ->post('/mappings/taxes', [
            'mappings' => 'not-an-array',
        ])
        ->assertSessionHasErrors('mappings');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/MappingControllerTest.php`
Expected: FAIL — route `/mappings` not found

- [x] **Step 3: Add routes to `routes/web.php`**

Inside the `Route::middleware('auth')->group(...)` block, add after the settings routes:

```php
Route::get('/mappings', [MappingController::class, 'index'])->name('mappings');
Route::post('/mappings/branches/sync', [MappingController::class, 'syncBranches'])->name('mappings.branches.sync');
Route::post('/mappings/taxes/sync', [MappingController::class, 'syncTaxes'])->name('mappings.taxes.sync');
Route::post('/mappings/branches', [MappingController::class, 'storeBranchMapping'])->name('mappings.branches.store');
Route::post('/mappings/taxes', [MappingController::class, 'storeTaxMapping'])->name('mappings.taxes.store');
```

- [x] **Step 4: Create validation request classes**

`app/Http/Requests/StoreBranchMappingRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*.foodics_id' => ['required', 'string', 'max:255'],
            'mappings.*.daftra_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/StoreTaxMappingRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*.foodics_id' => ['required', 'string', 'max:255'],
            'mappings.*.daftra_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [x] **Step 5: Create MappingController**

`app/Http/Controllers/MappingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\EntityMapping;
use App\Services\Daftra\TaxService;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\BranchService;
use App\Services\Foodics\TaxService as FoodicsTaxService;
use App\Http\Requests\StoreBranchMappingRequest;
use App\Http\Requests\StoreTaxMappingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MappingController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $branchMappings = EntityMapping::query()
            ->where('user_id', $user->id)
            ->where('type', 'branch')
            ->get()
            ->keyBy('foodics_id');

        $taxMappings = EntityMapping::query()
            ->where('user_id', $user->id)
            ->where('type', 'tax')
            ->get()
            ->keyBy('foodics_id');

        return view('mappings', [
            'branchMappings' => $branchMappings,
            'taxMappings' => $taxMappings,
            'foodicsBranches' => session('foodics_branches', []),
            'daftraBranches' => session('daftra_branches'),
            'foodicsTaxes' => session('foodics_taxes', []),
            'daftraTaxes' => session('daftra_taxes', []),
        ]);
    }

    public function syncBranches(BranchService $branchService, DaftraApiClient $daftraClient): RedirectResponse
    {
        $foodicsBranches = $branchService->fetchBranches();
        $daftraBranches = $daftraClient->tryGetBranches();

        return redirect()
            ->route('mappings')
            ->withInput()
            ->with('foodics_branches', $foodicsBranches)
            ->with('daftra_branches', $daftraBranches);
    }

    public function syncTaxes(FoodicsTaxService $foodicsTaxService, TaxService $daftraTaxService): RedirectResponse
    {
        $foodicsTaxes = $foodicsTaxService->fetchTaxes();
        $daftraTaxes = $daftraTaxService->listTaxes();

        return redirect()
            ->route('mappings')
            ->withInput()
            ->with('foodics_taxes', $foodicsTaxes)
            ->with('daftra_taxes', $daftraTaxes);
    }

    public function storeBranchMapping(StoreBranchMappingRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->input('mappings', []) as $mapping) {
            $foodicsId = (string) $mapping['foodics_id'];
            $daftraId = $mapping['daftra_id'];

            if ($daftraId === '' || $daftraId === null) {
                EntityMapping::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'branch')
                    ->where('foodics_id', $foodicsId)
                    ->delete();

                continue;
            }

            EntityMapping::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'branch',
                    'foodics_id' => $foodicsId,
                ],
                [
                    'daftra_id' => (int) $daftraId,
                    'metadata' => [],
                    'status' => 'synced',
                ],
            );
        }

        return redirect()
            ->route('mappings')
            ->with('status', __('Branch mappings saved successfully.'));
    }

    public function storeTaxMapping(StoreTaxMappingRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->input('mappings', []) as $mapping) {
            $foodicsId = (string) $mapping['foodics_id'];
            $daftraId = $mapping['daftra_id'];

            if ($daftraId === '' || $daftraId === null) {
                EntityMapping::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'tax')
                    ->where('foodics_id', $foodicsId)
                    ->delete();

                continue;
            }

            EntityMapping::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'tax',
                    'foodics_id' => $foodicsId,
                ],
                [
                    'daftra_id' => (int) $daftraId,
                    'metadata' => [],
                    'status' => 'synced',
                ],
            );
        }

        return redirect()
            ->route('mappings')
            ->with('status', __('Tax mappings saved successfully.'));
    }
}
```

- [x] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/MappingControllerTest.php`
Expected: PASS

- [x] **Step 7: Commit**

```bash
git add app/Http/Controllers/MappingController.php app/Http/Requests/StoreBranchMappingRequest.php app/Http/Requests/StoreTaxMappingRequest.php routes/web.php tests/Feature/MappingControllerTest.php
git commit -m "feat: add MappingController with sync and save endpoints for branches and taxes"
```

---

## Task 6: Mapping Page View

**Files:**
- Create: `resources/views/mappings.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (add nav link)
- Test: `tests/Feature/MappingPageTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\ProviderToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'foodics',
        'token' => 'fake-token',
        'refresh_token' => 'fake-refresh',
        'expires_at' => now()->addHour(),
    ]);
});

it('shows the mapping page with sync buttons', function () {
    $this->actingAs($this->user)
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Sync Branches')
        ->assertSee('Sync Taxes');
});

it('shows foodics branches after sync', function () {
    $this->actingAs($this->user)
        ->withSession([
            'foodics_branches' => [
                ['id' => 'fb-1', 'name' => 'Branch 1', 'reference' => 'B01'],
                ['id' => 'fb-2', 'name' => 'Branch 2', 'reference' => 'B02'],
            ],
            'daftra_branches' => [
                ['id' => 1, 'name' => 'Main Branch'],
                ['id' => 2, 'name' => 'Branch B'],
            ],
        ])
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Branch 1')
        ->assertSee('Branch 2')
        ->assertSee('Main Branch')
        ->assertSee('Branch B');
});

it('shows foodics taxes after sync', function () {
    $this->actingAs($this->user)
        ->withSession([
            'foodics_taxes' => [
                ['id' => 'ft-1', 'name' => 'VAT', 'rate' => 15],
            ],
            'daftra_taxes' => [
                ['id' => 10, 'name' => 'VAT', 'value' => 15],
            ],
        ])
        ->get('/mappings')
        ->assertOk()
        ->assertSee('VAT (15%)');
});

it('shows message when daftra branches are disabled', function () {
    $this->actingAs($this->user)
        ->withSession([
            'foodics_branches' => [
                ['id' => 'fb-1', 'name' => 'Branch 1', 'reference' => 'B01'],
            ],
            'daftra_branches' => null,
        ])
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Branch 1')
        ->assertSee('single default branch');
});

it('has mappings link in navigation', function () {
    $this->actingAs($this->user)
        ->get('/mappings')
        ->assertOk()
        ->assertSee(route('mappings'));
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/MappingPageTest.php`
Expected: FAIL — view `mappings` not found

- [x] **Step 3: Add nav link to layout**

In `resources/views/layouts/app.blade.php`, after the Products nav link (line 76) and before the Settings nav link (line 77), add:

```html
<a
    href="{{ route('mappings') }}"
    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('mappings') ? 'nav-active text-ink' : 'text-ink-muted hover:bg-surface-2 hover:text-ink' }}"
>
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
    </svg>
    {{ __('Mappings') }}
</a>
```

- [x] **Step 4: Create the mappings view**

`resources/views/mappings.blade.php`:

```blade
@extends('layouts.app')

@section('title', __('Mappings'))

@section('content')
<div class="max-w-4xl mx-auto" x-data="{ saving: false }">
    <h1 class="text-2xl font-semibold mb-6">{{ __('Mappings') }}</h1>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    {{-- Branch Mappings Section --}}
    <div class="bg-surface-1 rounded-lg shadow-sm border border-line p-6 card-accent mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium text-ink">{{ __('Branch Mappings') }}</h2>
            <form method="POST" action="{{ route('mappings.branches.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ __('Sync Branches') }}
                </button>
            </form>
        </div>

        @if(!empty($foodicsBranches))
            @if($daftraBranches === null)
                <x-alert type="info">
                    {{ __('Daftra branches plugin is not enabled. All Foodics branches will sync to the single default Daftra branch. You can configure the default branch in Settings.') }}
                </x-alert>
            @else
                <form method="POST" action="{{ route('mappings.branches.store') }}" @submit="saving = true">
                    @csrf
                    <div class="space-y-3">
                        @foreach($foodicsBranches as $branch)
                            <div class="flex items-center gap-4 py-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-ink truncate">{{ $branch['name'] }}</p>
                                    <p class="text-xs text-ink-muted">{{ $branch['reference'] ?? '' }}</p>
                                    <input type="hidden" name="mappings[{{ $loop->index }}][foodics_id]" value="{{ $branch['id'] }}">
                                </div>
                                <div class="w-48">
                                    <select
                                        name="mappings[{{ $loop->index }}][daftra_id]"
                                        class="w-full rounded-lg border border-line bg-surface-input text-ink px-3 py-2 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                                    >
                                        <option value="">{{ __('-- Not mapped --') }}</option>
                                        @foreach($daftraBranches as $daftraBranch)
                                            <option value="{{ $daftraBranch['id'] }}" {{ ($branchMappings[$branch['id']] ?? null)?->daftra_id == $daftraBranch['id'] ? 'selected' : '' }}>
                                                {{ $daftraBranch['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-4">
                        <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow disabled:opacity-60 disabled:cursor-not-allowed">
                            {{ __('Save Branch Mappings') }}
                        </button>
                    </div>
                </form>
            @endif
        @else
            <p class="text-sm text-ink-muted">{{ __('Click "Sync Branches" to fetch branches from Foodics and Daftra.') }}</p>
        @endif

        @if($branchMappings->isNotEmpty() && empty($foodicsBranches))
            <div class="mt-4 pt-4 border-t border-line">
                <h3 class="text-sm font-medium text-ink mb-2">{{ __('Saved Branch Mappings') }}</h3>
                <div class="space-y-2">
                    @foreach($branchMappings as $mapping)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-ink-muted">{{ $mapping->foodics_id }}</span>
                            <svg class="w-3 h-3 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            <span class="text-ink">{{ __('Daftra Branch') }} #{{ $mapping->daftra_id }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Tax Mappings Section --}}
    <div class="bg-surface-1 rounded-lg shadow-sm border border-line p-6 card-accent">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium text-ink">{{ __('Tax Mappings') }}</h2>
            <form method="POST" action="{{ route('mappings.taxes.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ __('Sync Taxes') }}
                </button>
            </form>
        </div>

        @if(!empty($foodicsTaxes))
            <p class="text-xs text-ink-muted mb-3">{{ __('Tax auto-matching is active: taxes are automatically matched by name and rate. Use manual mapping below to override.') }}</p>
            <form method="POST" action="{{ route('mappings.taxes.store') }}" @submit="saving = true">
                @csrf
                <div class="space-y-3">
                    @foreach($foodicsTaxes as $tax)
                        <div class="flex items-center gap-4 py-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-ink truncate">{{ $tax['name'] }} ({{ $tax['rate'] }}%)</p>
                                <input type="hidden" name="mappings[{{ $loop->index }}][foodics_id]" value="{{ $tax['id'] }}">
                            </div>
                            <div class="w-48">
                                <select
                                    name="mappings[{{ $loop->index }}][daftra_id]"
                                    class="w-full rounded-lg border border-line bg-surface-input text-ink px-3 py-2 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                                >
                                    <option value="">{{ __('-- Auto-match --') }}</option>
                                    @foreach($daftraTaxes as $daftraTax)
                                        <option value="{{ $daftraTax['id'] }}" {{ ($taxMappings[$tax['id']] ?? null)?->daftra_id == $daftraTax['id'] ? 'selected' : '' }}>
                                            {{ $daftraTax['name'] }} ({{ $daftraTax['value'] }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow disabled:opacity-60 disabled:cursor-not-allowed">
                        {{ __('Save Tax Mappings') }}
                    </button>
                </div>
            </form>
        @else
            <p class="text-sm text-ink-muted">{{ __('Click "Sync Taxes" to fetch taxes from Foodics and Daftra.') }}</p>
        @endif

        @if($taxMappings->isNotEmpty() && empty($foodicsTaxes))
            <div class="mt-4 pt-4 border-t border-line">
                <h3 class="text-sm font-medium text-ink mb-2">{{ __('Saved Tax Mappings') }}</h3>
                <div class="space-y-2">
                    @foreach($taxMappings as $mapping)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-ink-muted">{{ $mapping->foodics_id }}</span>
                            <svg class="w-3 h-3 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            <span class="text-ink">{{ __('Daftra Tax') }} #{{ $mapping->daftra_id }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
```

- [x] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/MappingPageTest.php`
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add resources/views/mappings.blade.php resources/views/layouts/app.blade.php tests/Feature/MappingPageTest.php
git commit -m "feat: add dedicated /mappings page with branch and tax mapping UI"
```

---

## Task 7: Per-Order Branch Resolution in SyncOrder

**Files:**
- Modify: `app/Services/SyncOrder.php`
- Test: `tests/Feature/Services/SyncOrder/BranchResolutionTest.php`

When syncing an order, if the order has a `branch.id` and there's a branch mapping in `entity_mappings`, use that mapped Daftra branch ID instead of the global default. This means we need to temporarily override the `DaftraApiClient`'s branch context for that order.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\EntityMapping;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use App\Services\Daftra\PaymentMethodService;
use App\Services\SyncCreditNote;
use App\Services\SyncOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Context::add('user', $this->user);
});

it('resolves branch mapping from entity_mappings for an order', function () {
    EntityMapping::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'branch',
        'foodics_id' => 'foodics-branch-1',
        'daftra_id' => 5,
        'status' => 'synced',
    ]);

    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(100);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-1',
        'reference' => '00100',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 100,
        'branch' => ['id' => 'foodics-branch-1', 'name' => 'Branch 1'],
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-1')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->daftra_id)->toBe(100);
    expect($invoice->foodics_metadata['branch_id'])->toBe('foodics-branch-1');
});

it('falls back to default branch when no branch mapping exists', function () {
    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(200);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-2',
        'reference' => '00200',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 50,
        'branch' => ['id' => 'unmapped-branch', 'name' => 'Unmapped'],
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-2')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->daftra_id)->toBe(200);
    expect($invoice->foodics_metadata['branch_id'])->toBe('unmapped-branch');
});

it('handles orders without branch data', function () {
    $mockInvoiceService = Mockery::mock(InvoiceService::class);
    $mockInvoiceService->shouldReceive('getInvoice')->andReturn([]);
    $mockInvoiceService->shouldReceive('createInvoice')->andReturn(300);
    $mockInvoiceService->shouldReceive('listInvoicePayments')->andReturn([]);
    $mockInvoiceService->shouldReceive('getInvoiceById')->andReturn(null);

    $mockProductService = Mockery::mock(ProductService::class);
    $mockProductService->shouldReceive('getProductByFoodicsData')->andReturn(1);

    $mockClientService = Mockery::mock(ClientService::class);
    $mockTaxService = Mockery::mock(TaxService::class);
    $mockTaxService->shouldReceive('resolveTaxId')->andReturn(1);

    $mockPaymentMethodService = Mockery::mock(PaymentMethodService::class);
    $mockPaymentMethodService->shouldReceive('beginPaymentMethodBatch');
    $mockPaymentMethodService->shouldReceive('endPaymentMethodBatch');

    $mockSyncCreditNote = Mockery::mock(SyncCreditNote::class);

    $this->app->instance(InvoiceService::class, $mockInvoiceService);
    $this->app->instance(ProductService::class, $mockProductService);
    $this->app->instance(ClientService::class, $mockClientService);
    $this->app->instance(TaxService::class, $mockTaxService);
    $this->app->instance(PaymentMethodService::class, $mockPaymentMethodService);
    $this->app->instance(SyncCreditNote::class, $mockSyncCreditNote);

    $order = [
        'id' => 'order-3',
        'reference' => '00300',
        'status' => 4,
        'business_date' => '2026-05-05',
        'total_price' => 75,
        'products' => [],
        'payments' => [],
        'charges' => [],
        'customer' => null,
    ];

    $syncOrder = $this->app->make(SyncOrder::class);
    $syncOrder->handle($order);

    $invoice = Invoice::where('user_id', $this->user->id)->where('foodics_id', 'order-3')->first();
    expect($invoice)->not->toBeNull();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/SyncOrder/BranchResolutionTest.php`
Expected: FAIL — the test will fail because `SyncOrder` doesn't yet resolve branch mappings or store `branch_id` in `foodics_metadata`

- [x] **Step 3: Modify SyncOrder to store branch context and resolve per-order**

In `app/Services/SyncOrder.php`, add a method to resolve the Daftra branch for an order, and store the branch mapping in the invoice's `foodics_metadata`. The key change is that `SyncOrder` will record which Foodics branch the order came from, and look up the corresponding Daftra branch mapping.

Add this method to `SyncOrder`:

```php
protected function resolveDaftraBranchId(array $order): ?int
{
    $foodicsBranchId = $order['branch']['id'] ?? null;

    if ($foodicsBranchId === null) {
        return null;
    }

    $userId = Context::get('user')?->id;

    $mapping = EntityMapping::query()
        ->where('user_id', $userId)
        ->where('type', 'branch')
        ->where('foodics_id', $foodicsBranchId)
        ->first();

    return $mapping?->daftra_id;
}
```

Add the import at the top:

```php
use App\Models\EntityMapping;
```

In `createPendingInvoice()`, update the `foodics_metadata` to include the branch:

Change the `foodics_metadata` in the `create` call from `[]` to:

```php
'foodics_metadata' => [
    'branch_id' => $order['branch']['id'] ?? null,
],
```

And in the `fill` call from `[]` to:

```php
'foodics_metadata' => [
    'branch_id' => $order['branch']['id'] ?? null,
],
```

In `runSync()`, after resolving the Daftra invoice, update the `DaftraApiClient`'s branch context if a branch mapping exists. Add this at the start of `runSync()`:

```php
protected function runSync(array $order, Invoice $invoice): void
{
    $this->taxMap = [];
    $this->resolveUniqueTaxes($order);

    $this->paymentMethodMap = [];
    $this->resolveUniquePaymentMethods($order);

    $mappedBranchId = $this->resolveDaftraBranchId($order);
    if ($mappedBranchId !== null) {
        $this->invoiceService->setBranchOverride($mappedBranchId);
    }

    $daftraInvoiceId = $this->resolveDaftraInvoiceId($order, $invoice);

    if ($invoice->daftra_id !== $daftraInvoiceId) {
        $invoice->update(['daftra_id' => $daftraInvoiceId]);
    }

    $this->syncPaymentsIfMissing($order['payments'] ?? [], $daftraInvoiceId);

    $daftraInvoice = $this->invoiceService->getInvoiceById($daftraInvoiceId);
    if ($daftraInvoice !== null) {
        $invoice->update([
            'daftra_no' => $daftraInvoice['no'] ?? null,
            'daftra_metadata' => [
                'client_id' => $daftraInvoice['client_id'] ?? null,
            ],
        ]);
    }

    $invoice->update(['status' => InvoiceSyncStatus::Synced]);

    $this->invoiceService->clearBranchOverride();
}
```

In `app/Services/Daftra/InvoiceService.php`, add branch override methods:

```php
private ?int $branchOverride = null;

public function setBranchOverride(int $branchId): void
{
    $this->branchOverride = $branchId;
}

public function clearBranchOverride(): void
{
    $this->branchOverride = null;
}
```

And in the `InvoiceService` constructor, we need to pass through the branch override to `DaftraApiClient`. Since `InvoiceService` uses `$this->daftraClient` (which is `DaftraApiClient`), we need to make `DaftraApiClient` support temporary branch override. Add to `DaftraApiClient`:

```php
private ?int $branchOverride = null;

public function setBranchOverride(int $branchId): void
{
    $this->branchOverride = $branchId;
}

public function clearBranchOverride(): void
{
    $this->branchOverride = null;
}
```

Then modify `appendBranchIdToUrl` to use the override:

```php
private function appendBranchIdToUrl(string $url): string
{
    $effectiveBranchId = $this->branchOverride ?? $this->branchId;

    if ($effectiveBranchId === null || (string) $effectiveBranchId === '' || (string) $effectiveBranchId === '1') {
        return $url;
    }

    if (str_contains($url, 'request_branch_id=')) {
        return $url;
    }

    $separator = str_contains($url, '?') ? '&' : '?';

    return $url.$separator.'request_branch_id='.urlencode((string) $effectiveBranchId);
}
```

Then in `InvoiceService`, delegate to the client:

```php
public function setBranchOverride(int $branchId): void
{
    $this->daftraClient->setBranchOverride($branchId);
}

public function clearBranchOverride(): void
{
    $this->daftraClient->clearBranchOverride();
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/SyncOrder/BranchResolutionTest.php`
Expected: PASS

- [x] **Step 5: Run existing SyncOrder tests to verify no regressions**

Run: `php artisan test --compact tests/Feature/Services/SyncOrderTest.php tests/Feature/Services/SyncOrder/`
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add app/Services/SyncOrder.php app/Services/Daftra/InvoiceService.php app/Services/Daftra/DaftraApiClient.php tests/Feature/Services/SyncOrder/BranchResolutionTest.php
git commit -m "feat: add per-order branch resolution in SyncOrder using entity_mappings"
```

---

## Task 8: Run Full Test Suite and Format

**Files:** No new files

- [x] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: ALL PASS

- [x] **Step 2: Run Pint formatter**

Run: `vendor/bin/pint --dirty --format agent`
Expected: All files formatted

- [x] **Step 3: Re-run tests after formatting**

Run: `php artisan test --compact`
Expected: ALL PASS

- [x] **Step 4: Final commit if formatting changed anything**

```bash
git add -A
git commit -m "style: format code with pint"
```

---

## Self-Review Checklist

### 1. Spec coverage

| Requirement | Task |
|------------|------|
| Fetch branches from Foodics API | Task 1 (BranchService) |
| Fetch taxes from Foodics API | Task 2 (Foodics TaxService) |
| Fetch branches from Daftra API | Task 3 (existing getBranches/tryGetBranches) |
| Fetch taxes from Daftra API | Task 4 (listTaxes) |
| Dedicated /mappings page | Task 5 (controller) + Task 6 (view) |
| Branch mapping when Daftra branches enabled | Task 5 (dropdown mapping) |
| Fallback to default when Daftra branches disabled | Task 5 (info message) + Task 7 (null fallback) |
| Tax mapping (manual + auto-match coexist) | Task 5 (storeTaxMapping) + Task 4 (listTaxes) |
| Per-order branch resolution in sync | Task 7 |
| Nav link for Mappings page | Task 6 (layout) |

### 2. Placeholder scan

No TBD, TODO, or placeholder text found in any task.

### 3. Type consistency

- `EntityMapping::daftra_id` is `int` — all mapping saves cast to `(int)` consistently.
- `BranchService::fetchBranches()` returns `array<int, array<string, mixed>>` — used as `$branch['id']` (string UUID) consistently.
- `TaxService::listTaxes()` returns `array<int, array{id: int, name: string, value: float}>` — used as `$daftraTax['id']` and `$daftraTax['value']` consistently.
- `DaftraApiClient::$branchOverride` is `?int` — matches `$mapping->daftra_id` which is `int`.
- `SyncOrder::resolveDaftraBranchId()` returns `?int` — used correctly in `runSync()`.
