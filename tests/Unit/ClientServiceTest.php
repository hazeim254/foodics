<?php

use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['daftra_id' => '12345']);
    ProviderToken::create([
        'user_id' => $this->user->id,
        'provider' => 'daftra',
        'token' => 'fake-token',
        'refresh_token' => 'fake-refresh',
        'expires_at' => now()->addHour(),
    ]);
    Context::add('user', $this->user);
});

it('searchClients returns array from Daftra API response', function () {
    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response([
            ['id' => 1, 'text' => 'Acme Corp', 'avatar' => 'https://example.com/avatar1.png'],
            ['id' => 2, 'text' => 'Acme Inc', 'avatar' => 'https://example.com/avatar2.png'],
        ]),
    ]);

    $client = app(ClientService::class);
    $results = $client->searchClients('acme');

    expect($results)->toBeArray()
        ->and(count($results))->toBe(2)
        ->and($results[0]['text'])->toBe('Acme Corp')
        ->and($results[1]['text'])->toBe('Acme Inc');
});

it('searchClients throws RuntimeException on failed response', function () {
    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response(['error' => 'Server Error'], 500),
    ]);

    $client = app(ClientService::class);
    $client->searchClients('acme');
})->throws(RuntimeException::class, 'Daftra client search failed: HTTP 500');

it('getDefaultClient returns first client from Daftra API response', function () {
    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response([
            ['id' => 42, 'text' => 'Acme Corp', 'avatar' => 'https://example.com/avatar.png'],
        ]),
    ]);

    $client = app(ClientService::class);
    $result = $client->getDefaultClient(42);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe(42)
        ->and($result['text'])->toBe('Acme Corp');
});

it('getDefaultClient returns empty array when no client is found', function () {
    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response([]),
    ]);

    $client = app(ClientService::class);
    $result = $client->getDefaultClient(999);

    expect($result)->toBe([]);
});

it('getDefaultClient throws RuntimeException on failed response', function () {
    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response(['error' => 'Server Error'], 500),
    ]);

    $client = app(ClientService::class);
    $client->getDefaultClient(42);
})->throws(RuntimeException::class, 'Daftra client search failed: HTTP 500');

it('findClientById returns client array when found', function () {
    Http::fake([
        '*/v2/api/entity/client/list*' => Http::response([
            'data' => [
                ['id' => 42, 'name' => 'Test Client', 'avatar' => 'https://example.com/avatar.png'],
            ],
        ]),
    ]);

    $client = app(ClientService::class);
    $result = $client->findClientById(42);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe(42)
        ->and($result['name'])->toBe('Test Client');
});

it('findClientById returns null when not found', function () {
    Http::fake([
        '*/v2/api/entity/client/list*' => Http::response(['data' => []]),
    ]);

    $client = app(ClientService::class);
    $result = $client->findClientById(999);

    expect($result)->toBeNull();
});

it('findClientById returns null on failed response', function () {
    Http::fake([
        '*/v2/api/entity/client/list*' => Http::response(['error' => 'Server Error'], 500),
    ]);

    $client = app(ClientService::class);
    $result = $client->findClientById(42);

    expect($result)->toBeNull();
});
