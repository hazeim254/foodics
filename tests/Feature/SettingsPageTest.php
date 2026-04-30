<?php

use App\Enums\SettingKey;
use App\Models\ProviderToken;
use App\Models\User;
use App\Services\Daftra\DaftraApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function mockDaftraWithBranches(array $branches, bool $withGet = true): DaftraApiClient
{
    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('tryGetBranches')->andReturn($branches);

    if ($withGet) {
        $mockClient->shouldReceive('get')->andReturn(Http::response(['data' => []]));
    }

    app()->instance(DaftraApiClient::class, $mockClient);

    return $mockClient;
}

function createUserWithDaftraConnection(array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['daftra_id' => '12345'], $attributes));
    ProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'daftra',
        'token' => 'fake-token',
        'refresh_token' => 'fake-refresh',
        'expires_at' => now()->addHour(),
    ]);

    return $user;
}

it('redirects unauthenticated users from GET /settings to login', function () {
    $this->get('/settings')->assertRedirect('/login');
});

it('redirects unauthenticated users from POST /settings to login', function () {
    $this->post('/settings', ['daftra_default_client_id' => '123'])->assertRedirect('/login');
});

it('shows the settings form with branches', function () {
    $user = createUserWithDaftraConnection();
    $user->setSetting(SettingKey::DaftraDefaultBranchId, '7');

    mockDaftraWithBranches([
        ['id' => 1, 'name' => 'Main Branch'],
        ['id' => 2, 'name' => 'Branch 2'],
    ]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Main Branch')
        ->assertSee('Branch 2')
        ->assertSee('Save Settings')
        ->assertSee('Default Client')
        ->assertSee('Default Branch');
});

it('shows search input when no saved client exists', function () {
    $user = createUserWithDaftraConnection();

    mockDaftraWithBranches([['id' => 1, 'name' => 'Main Branch']]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Search for a client…');
});

it('shows saved client name when a default client is configured', function () {
    $user = createUserWithDaftraConnection();
    $user->setSetting(SettingKey::DaftraDefaultClientId, '42');

    Http::fake([
        '*/v2/api/entity/branch/list*' => Http::response(['data' => []]),
        '*/v2/api/entity/client/list*' => Http::response([
            'data' => [
                ['id' => 42, 'name' => 'Acme Corp', 'avatar' => ''],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Acme Corp');
});

it('returns 503 json when daftra search fails', function () {
    $user = createUserWithDaftraConnection();

    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response([], 500),
    ]);

    $this->actingAs($user)
        ->getJson('/settings/search-clients?query=acme')
        ->assertStatus(503)
        ->assertJson(['data' => []]);
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
    $user = createUserWithDaftraConnection();

    mockDaftraWithBranches([
        ['id' => 1, 'name' => 'Main Branch'],
    ]);

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

it('shows branch dropdown with options from daftra', function () {
    $user = createUserWithDaftraConnection();

    mockDaftraWithBranches([
        ['id' => 1, 'name' => 'Main Branch'],
        ['id' => 2, 'name' => 'Branch 2'],
    ]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Main Branch')
        ->assertSee('Branch 2')
        ->assertSee('name="daftra_default_branch_id"', escape: false);
});

it('hides branch field when branches are disabled in daftra', function () {
    $user = createUserWithDaftraConnection();

    $mockClient = Mockery::mock(DaftraApiClient::class);
    $mockClient->shouldReceive('tryGetBranches')->andReturn(null);
    $this->app->instance(DaftraApiClient::class, $mockClient);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertDontSee('daftra_default_branch_id')
        ->assertDontSee('Default Branch');
});

it('selects the current branch in the dropdown', function () {
    $user = createUserWithDaftraConnection();
    $user->setSetting(SettingKey::DaftraDefaultBranchId, '2');

    mockDaftraWithBranches([
        ['id' => 1, 'name' => 'Main Branch'],
        ['id' => 2, 'name' => 'Branch 2'],
    ]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('value="2" selected', escape: false);
});

it('searches clients via GET /settings/search-clients', function () {
    $user = createUserWithDaftraConnection();

    Http::fake([
        '*/v2/api/entity/client/filter-auto-suggest*' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Acme Corp', 'avatar' => 'https://example.com/avatar.png'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get('/settings/search-clients?query=acme')
        ->assertOk()
        ->assertJson(['data' => [['id' => 1, 'name' => 'Acme Corp', 'avatar' => 'https://example.com/avatar.png']]]);
});

it('search requires query parameter', function () {
    $user = createUserWithDaftraConnection();

    $this->actingAs($user)
        ->getJson('/settings/search-clients')
        ->assertUnprocessable();
});

it('search requires minimum 2 characters', function () {
    $user = createUserWithDaftraConnection();

    $this->actingAs($user)
        ->getJson('/settings/search-clients?query=a')
        ->assertUnprocessable();
});

it('redirects guest from search endpoint', function () {
    $this->get('/settings/search-clients?query=acme')
        ->assertRedirect('/login');
});
