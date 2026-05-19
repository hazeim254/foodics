<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the landing page to guests at /', function () {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('landing')
        ->assertSee(__('Your Foodics sales land in Daftra.'));
});

it('does not redirect guests away from /', function () {
    $response = $this->get('/');

    $response->assertOk();
    expect($response->isRedirection())->toBeFalse();
});

it('shows the guest CTA pointing to login', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('login'))
        ->assertSee(__('Connect your accounts'));
});

it('shows the guest CTA translated in arabic', function () {
    app()->setLocale('ar');

    $this->get('/')
        ->assertOk()
        ->assertSee(__('Connect your accounts'));
});

it('shows the landing page to authenticated users without redirecting', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertViewIs('landing');
});

it('swaps the CTA to dashboard for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk()
        ->assertSee(route('dashboard'))
        ->assertSee(__('Dashboard'))
        ->assertDontSee(__('Connect your accounts'));
});

it('swaps the CTA to dashboard for authenticated users in arabic', function () {
    app()->setLocale('ar');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee(__('Dashboard'));
});

it('renders the landing page in arabic with rtl direction', function () {
    app()->setLocale('ar');

    $this->get('/')
        ->assertOk()
        ->assertSee(__('Your Foodics sales land in Daftra.'))
        ->assertSee('dir="rtl"', false);
});

it('language switcher form posts to the language switch route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('action="'.route('language.switch').'"', false);
});

it('renders the configured app name', function () {
    config(['app.name' => 'Daftrics']);

    $this->get('/')
        ->assertOk()
        ->assertSee('Daftrics');
});

it('keeps the dashboard accessible at /dashboard for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertViewIs('dashboard');
});

it('redirects guests from /dashboard to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
