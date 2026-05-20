<?php

namespace App\Providers;

use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\FoodicsApiClient;
use App\Services\Http\CurlCommandBuilder;
use App\Services\UserContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(UserContext::class);
        $this->app->scoped(DaftraApiClient::class);
        $this->app->scoped(FoodicsApiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Response::macro('toCurl', function (): string {
            /** @var Response $this */
            $request = $this->transferStats?->getRequest();

            return $request ? CurlCommandBuilder::build($request) : '';
        });

        View::composer(['layouts.app', 'login', 'landing'], function ($view) {
            $fontMap = [
                'en' => ['inter' => 'Inter', 'instrument-sans' => 'Instrument Sans', 'noto' => 'Noto Sans'],
                'ar' => ['cairo' => 'Cairo', 'ibm-plex-arabic' => 'IBM Plex Sans Arabic', 'noto-arabic' => 'Noto Sans Arabic'],
            ];
            $view->with([
                'enFont' => $fontMap['en'][request()->get('en_font')] ?? 'Noto Sans',
                'arFont' => $fontMap['ar'][request()->get('ar_font')] ?? 'Noto Sans Arabic',
            ]);
        });
    }
}
