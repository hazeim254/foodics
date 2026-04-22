<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }} — Connect Accounts</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-white dark:bg-[#0a0a0a] font-[Instrument_Sans,ui-sans-serif,system-ui,sans-serif] antialiased">

        <div class="flex min-h-screen">
            <div class="absolute top-4 end-4 z-10">
                <form method="POST" action="{{ route('language.switch') }}" class="inline-flex">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button type="submit" class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC] bg-white/80 dark:bg-black/40 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]">
                        {{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
                    </button>
                </form>
            </div>

            {{-- Left decorative panel (hidden on mobile) --}}
            <div class="hidden lg:flex lg:w-2/5 flex-col items-center justify-center relative overflow-hidden bg-[#1b1b18] dark:bg-[#161615]">

                {{-- Background glow blobs --}}
                <div class="absolute inset-0 pointer-events-none">
                    <div class="absolute top-1/4 left-1/4 w-72 h-72 rounded-full opacity-20" style="background: radial-gradient(circle, #4A90D9 0%, transparent 70%);"></div>
                    <div class="absolute bottom-1/4 right-1/4 w-72 h-72 rounded-full opacity-20" style="background: radial-gradient(circle, #FF4433 0%, transparent 70%);"></div>
                </div>

                {{-- Brand logos connected by a line --}}
                <div class="relative z-10 flex flex-col items-center gap-5 px-12 w-full max-w-xs">

                    {{-- Daftra logo card --}}
                    <div class="w-full flex items-center justify-center rounded-2xl px-8 py-5 shadow-lg" style="background-color: #4A90D9;">
                        <img
                            src="https://www.daftra.com/themed/multi_language/images/logos/daftra-ar.svg"
                            alt="Daftra"
                            class="h-7 w-auto"
                            style="filter: brightness(0) invert(1);"
                        >
                    </div>

                    {{-- Connector --}}
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-px h-4 bg-white/20"></div>
                        <div class="w-5 h-5 rounded-full border-2 border-white/30 flex items-center justify-center">
                            <div class="w-1.5 h-1.5 rounded-full bg-white/60"></div>
                        </div>
                        <div class="w-px h-4 bg-white/20"></div>
                    </div>

                    {{-- Foodics logo card --}}
                    <div class="w-full flex items-center justify-center rounded-2xl px-8 py-5 shadow-lg" style="background-color: #FF4433;">
                        <img
                            src="https://www.foodics.com/wp-content/uploads/2021/12/foodics-logo.svg"
                            alt="Foodics"
                            class="h-7 w-auto"
                            style="filter: brightness(0) invert(1);"
                        >
                    </div>

                    <p class="mt-4 text-center text-sm text-white/40">Seamless integration between your platforms</p>
                </div>
            </div>

            {{-- Right panel --}}
            <div class="flex flex-1 flex-col items-center justify-center px-6 py-12 lg:px-12">
                <div class="w-full max-w-sm">

                    {{-- Header --}}
                    <div class="mb-10 text-center">
                        <h1 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Connect your accounts</h1>
                        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Connect both accounts to get started</p>
                    </div>

                    {{-- Provider cards --}}
                    <div class="flex flex-col gap-4">

                        {{-- Daftra --}}
                        <div>
                            @if (session()->has('daftra_account'))
                                <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-4 dark:border-green-800/50 dark:bg-green-900/20">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-800/40">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-green-600 dark:text-green-400">
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-green-800 dark:text-green-300">Daftra Connected</p>
                                        <p class="truncate text-xs text-green-600 dark:text-green-500">{{ session('daftra_account.subdomain') }}</p>
                                    </div>
                                </div>
                            @else
                                <a href="{{ route('daftra.auth') }}"
                                   class="flex w-full items-center justify-center gap-3 rounded-xl px-5 py-4 text-sm font-semibold text-white shadow-sm transition-all hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                   style="background-color: #4A90D9;">
                                    <img
                                        src="https://www.daftra.com/themed/multi_language/images/logos/daftra-ar.svg"
                                        alt="Daftra"
                                        class="h-4 w-auto shrink-0"
                                        style="filter: brightness(0) invert(1);"
                                    >
                                    <span>Connect</span>
                                </a>
                            @endif
                        </div>

                        {{-- Foodics --}}
                        <div>
                            @if (session()->has('foodics_account'))
                                <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-4 dark:border-green-800/50 dark:bg-green-900/20">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-800/40">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-green-600 dark:text-green-400">
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-green-800 dark:text-green-300">Foodics Connected</p>
                                        <p class="truncate text-xs text-green-600 dark:text-green-500">{{ session('foodics_account.business_name') }}</p>
                                    </div>
                                </div>
                            @else
                                <a href="{{ route('foodics.auth') }}"
                                   class="flex w-full items-center justify-center gap-3 rounded-xl px-5 py-4 text-sm font-semibold text-white shadow-sm transition-all hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                   style="background-color: #FF4433;">
                                    <img
                                        src="https://www.foodics.com/wp-content/uploads/2021/12/foodics-logo.svg"
                                        alt="Foodics"
                                        class="h-4 w-auto shrink-0"
                                        style="filter: brightness(0) invert(1);"
                                    >
                                    <span>Connect</span>
                                </a>
                            @endif
                        </div>

                    </div>

                </div>
            </div>

        </div>

    </body>
</html>
