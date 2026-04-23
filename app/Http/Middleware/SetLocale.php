<?php

namespace App\Http\Middleware;

use App\Enums\SettingKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        if (auth()->check()) {
            $userLocale = auth()->user()->setting(SettingKey::Locale);

            if ($userLocale !== null) {
                $request->session()->put('locale', $userLocale);

                return $userLocale;
            }
        }

        return config('app.locale');
    }
}
