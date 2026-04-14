<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects authenticated user from login to home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect(route('home'));
});

it('shows login page for guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertViewIs('login');
});

it('shows login page with no providers connected', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('daftra-ar.svg', false)
        ->assertSee('foodics-logo.svg', false);
});

it('shows daftra connected state when daftra_account in session', function () {
    $this->withSession(['daftra_account' => ['subdomain' => 'myshop', 'site_id' => 1]])
        ->get('/login')
        ->assertOk()
        ->assertSee('Daftra Connected')
        ->assertSee('foodics-logo.svg', false);
});

it('shows foodics connected state when foodics_account in session', function () {
    $this->withSession(['foodics_account' => ['business_name' => 'My Restaurant', 'business_id' => 'abc']])
        ->get('/login')
        ->assertOk()
        ->assertSee('Foodics Connected')
        ->assertSee('daftra-ar.svg', false);
});

it('redirects to home when both providers are in session', function () {
    $this->withSession([
        'daftra_account' => ['subdomain' => 'myshop', 'site_id' => 1],
        'foodics_account' => ['business_name' => 'My Restaurant', 'business_id' => 'abc'],
    ])
        ->get('/login')
        ->assertRedirect(route('home'));
});

it('redirects unauthenticated users from home to login', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

it('allows authenticated users to access home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();
});
