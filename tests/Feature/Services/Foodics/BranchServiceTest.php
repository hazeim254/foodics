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
        ->andReturn(fakeFoodicsBranchResponse($branchesData));

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
        ->andReturn(fakeFoodicsBranchResponse(['data' => []]));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(BranchService::class);
    $branches = $service->fetchBranches();

    expect($branches)->toBe([]);
});

it('fetches branches across multiple pages', function () {
    $page1 = ['data' => array_map(fn ($i) => ['id' => "branch-$i", 'name' => "Branch $i", 'reference' => "B0$i"], range(1, 50))];
    $page2 = ['data' => [['id' => 'branch-51', 'name' => 'Branch 51', 'reference' => 'B051']]];

    $mockClient = Mockery::mock(FoodicsApiClient::class);
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::on(fn ($p) => ! isset($p['after'])))
        ->once()
        ->andReturn(fakeFoodicsBranchResponse($page1, 'cursor-page-2'));
    $mockClient->shouldReceive('get')
        ->with('/v5/branches', Mockery::on(fn ($p) => ($p['after'] ?? null) === 'cursor-page-2'))
        ->once()
        ->andReturn(fakeFoodicsBranchResponse($page2, null));

    $this->app->instance(FoodicsApiClient::class, $mockClient);

    $service = $this->app->make(BranchService::class);
    $branches = $service->fetchBranches();

    expect($branches)->toHaveCount(51);
});

function fakeFoodicsBranchResponse(array $json, ?string $nextCursor = null): object
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