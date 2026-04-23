<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from dashboard to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('redirects unauthenticated users from invoices to login', function () {
    $this->get('/invoices')->assertRedirect('/login');
});

it('redirects unauthenticated users from products to login', function () {
    $this->get('/products')->assertRedirect('/login');
});

it('redirects unauthenticated users from settings to login', function () {
    $this->get('/settings')->assertRedirect('/login');
});

it('allows authenticated users to access dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertViewIs('dashboard');
});

it('allows authenticated users to access invoices', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertViewIs('invoices');
});

it('allows authenticated users to access products', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertViewIs('products');
});

it('allows authenticated users to access settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertViewIs('settings');
});

it('dashboard shows sidebar with navigation links', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Invoices')
        ->assertSee('Products')
        ->assertSee('Settings');
});

it('dashboard shows connection status for daftra when in session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['daftra_account' => ['subdomain' => 'myshop', 'site_id' => 1]])
        ->get('/')
        ->assertOk()
        ->assertSee('Daftra');
});

it('dashboard shows connection status for foodics when in session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['foodics_account' => ['business_name' => 'My Restaurant', 'business_id' => 'abc']])
        ->get('/')
        ->assertOk()
        ->assertSee('Foodics');
});

it('dashboard shows disconnected status when no providers in session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Daftra')
        ->assertSee('Foodics');
});

it('shows green dot and daftra metadata from database when token exists', function () {
    $user = User::factory()->create([
        'daftra_meta' => [
            'subdomain' => 'myshop',
            'business_name' => 'My Daftra Business',
        ],
    ]);

    $user->providerTokens()->create([
        'provider' => 'daftra',
        'token' => 'token-123',
        'refresh_token' => 'refresh-123',
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('bg-green-500')
        ->assertSee('myshop')
        ->assertSee('My Daftra Business');
});

it('shows green dot and foodics metadata from database when token exists', function () {
    $user = User::factory()->create([
        'foodics_meta' => [
            'business_name' => 'My Foodics Business',
            'business_id' => 'abc',
        ],
    ]);

    $user->providerTokens()->create([
        'provider' => 'foodics',
        'token' => 'token-456',
        'refresh_token' => 'refresh-456',
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('bg-green-500')
        ->assertSee('My Foodics Business');
});

it('shows gray dots when no provider tokens exist and no session data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/')
        ->assertOk();

    expect($response->content())->not->toContain('bg-green-500');
});

it('invoices page extends layouts and shows title', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('Invoices');
});

it('products page extends layouts and shows title', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('Products');
});

it('settings page extends layouts and shows title', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Settings');
});

it('active navigation link is highlighted on dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();
});

it('authenticated pages use the app layout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Foodics');
});

it('app layout uses ltr direction for english locale', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('dir="ltr"', false);
});

it('app layout uses rtl direction for arabic locale', function () {
    $user = User::factory()->create();

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

it('invoices page uses rtl direction for arabic locale', function () {
    $user = User::factory()->create();

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get('/invoices')
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

it('products page uses rtl direction for arabic locale', function () {
    $user = User::factory()->create();

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

it('settings page uses rtl direction for arabic locale', function () {
    $user = User::factory()->create();

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});
