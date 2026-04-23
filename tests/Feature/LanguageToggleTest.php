<?php

use App\Enums\SettingKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('switches locale to arabic via post request', function () {
    $response = $this->post(route('language.switch'), ['locale' => 'ar']);

    $response->assertRedirect();
    expect(session('locale'))->toBe('ar');
});

it('switches locale to english via post request', function () {
    $this->withSession(['locale' => 'ar'])
        ->post(route('language.switch'), ['locale' => 'en'])
        ->assertRedirect();

    expect(session('locale'))->toBe('en');
});

it('persists locale to user settings when authenticated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('language.switch'), ['locale' => 'ar'])
        ->assertRedirect();

    expect($user->fresh()->setting(SettingKey::Locale))->toBe('ar');
    expect(session('locale'))->toBe('ar');
});

it('falls back to default locale when no session or setting exists', function () {
    $this->get('/login')
        ->assertOk();

    expect(app()->getLocale())->toBe(config('app.locale'));
});

it('applies locale from session for guests', function () {
    $this->withSession(['locale' => 'ar'])
        ->get('/login')
        ->assertOk();

    expect(app()->getLocale())->toBe('ar');
});

it('applies locale from user settings when authenticated', function () {
    $user = User::factory()->create();
    $user->setSetting(SettingKey::Locale, 'ar');

    $this->actingAs($user)
        ->get('/')
        ->assertOk();

    expect(app()->getLocale())->toBe('ar');
    expect(session('locale'))->toBe('ar');
});

it('ignores invalid locale and defaults to english', function () {
    $this->post(route('language.switch'), ['locale' => 'fr'])
        ->assertRedirect();

    expect(session('locale'))->toBe('en');
});

it('shows language toggle button on login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('/language')
        ->assertSee('العربية');
});

it('shows english toggle button when locale is arabic on login page', function () {
    $this->withSession(['locale' => 'ar'])
        ->get('/login')
        ->assertOk()
        ->assertSee('English');
});

it('shows language toggle button on dashboard layout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('/language')
        ->assertSee('العربية');
});

it('shows english toggle button when locale is arabic on dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['locale' => 'ar'])
        ->get('/')
        ->assertOk()
        ->assertSee('English');
});
