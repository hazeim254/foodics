<?php

namespace App\Providers;

use App\Services\Daftra\DaftraApiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(DaftraApiClient::class, function ($app) {
            $user = \Context::get('user');
            if (! $user) {
                throw new \Exception('User not found in context');
            }

            return new DaftraApiClient($user);
        });
    }
}
