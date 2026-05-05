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
            'daftra_branches_disabled' => false,
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
            'daftra_branches_disabled' => true,
            'daftra_branches' => [],
        ])
        ->get('/mappings')
        ->assertOk()
        ->assertSee('Daftra branches plugin');
});

it('has mappings link in navigation', function () {
    $this->actingAs($this->user)
        ->get('/mappings')
        ->assertOk()
        ->assertSee(route('mappings'));
});