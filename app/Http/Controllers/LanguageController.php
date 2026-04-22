<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $locale = $request->input('locale', 'en');

        if (! in_array($locale, ['en', 'ar'])) {
            $locale = 'en';
        }

        $request->session()->put('locale', $locale);

        if (auth()->check()) {
            $request->user()->setSetting(SettingKey::Locale, $locale);
        }

        return redirect()->back();
    }
}
