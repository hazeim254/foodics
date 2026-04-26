<?php

namespace App\Providers;

use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Http\CurlCommandBuilder;
use Illuminate\Http\Client\Response;
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
            $user = \Context::get('user') ?? auth()->user();
            if (! $user) {
                throw new \Exception('User not found in context');
            }

            return new DaftraApiClient($user);
        });

        $this->app->bind(FoodicsApiClient::class, function ($app) {
            $user = \Context::get('user');
            if (! $user) {
                throw new \Exception('User not found in context');
            }

            return new FoodicsApiClient($user);
        });

        Response::macro('toCurl', function (): string {
            /** @var Response $this */
            $request = $this->transferStats?->getRequest();

            return $request ? CurlCommandBuilder::build($request) : '';
        });
    }
}
