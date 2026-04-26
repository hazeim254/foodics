<?php

use App\Enums\SettingKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from GET /settings to login', function () {
    $this->get('/settings')->assertRedirect('/login');
});

it('redirects unauthenticated users from POST /settings to login', function () {
    $this->post('/settings', ['daftra_default_client_id' => '123'])->assertRedirect('/login');
});

it('shows the settings form with the current value pre-filled', function () {
    $user = User::factory()->create();
    $user->setSetting(SettingKey::DaftraDefaultClientId, '42');
    $user->setSetting(SettingKey::DaftraDefaultBranchId, '7');

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('value="42"', escape: false)
        ->assertSee('value="7"', escape: false)
        ->assertSee('Save Settings')
        ->assertSee('Default Client ID')
        ->assertSee('Default Branch ID');
});

it('shows an empty input when the setting is not set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('value=""', escape: false);
});

it('saves the daftra default client id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => '99'])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->setting(SettingKey::DaftraDefaultClientId))->toBe('99');
});

it('clears the daftra default client id when submitting empty', function () {
    $user = User::factory()->create();
    $user->setSetting(SettingKey::DaftraDefaultClientId, '99');

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => ''])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->setting(SettingKey::DaftraDefaultClientId))->toBeNull();
});

it('flashes a success message after saving', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => '10']);

    $response->assertSessionHas('status', 'Settings updated successfully.');
});

it('rejects strings longer than 255 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => str_repeat('a', 256)])
        ->assertSessionHasErrors('daftra_default_client_id');
});

it('preserves old input on validation failure', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => str_repeat('a', 256)])
        ->assertSessionHasInput('daftra_default_client_id', str_repeat('a', 256));
});

it('displays the flash message on the settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_client_id' => '10']);

    $this->actingAs($user)
        ->get('/settings')
        ->assertSee('Settings updated successfully.');
});

it('saves the daftra default branch id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => '5'])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->setting(SettingKey::DaftraDefaultBranchId))->toBe('5');
});

it('normalizes branch id 1 to null on save', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => '1'])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->setting(SettingKey::DaftraDefaultBranchId))->toBeNull();
});

it('clears the branch id when submitting empty', function () {
    $user = User::factory()->create();
    $user->setSetting(SettingKey::DaftraDefaultBranchId, '5');

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => ''])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->setting(SettingKey::DaftraDefaultBranchId))->toBeNull();
});

it('rejects non-integer branch id values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => 'abc'])
        ->assertSessionHasErrors('daftra_default_branch_id');
});

it('rejects negative branch id values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => '-1'])
        ->assertSessionHasErrors('daftra_default_branch_id');
});

it('rejects zero as branch id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings', ['daftra_default_branch_id' => '0'])
        ->assertSessionHasErrors('daftra_default_branch_id');
});
