<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr' }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @stack('styles')
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] min-h-screen">
    <div x-data="{ sidebarOpen: false, isDesktop: window.innerWidth >= 1024 }" x-init="window.addEventListener('resize', () => { isDesktop = window.innerWidth >= 1024 })" @keydown.escape="sidebarOpen = false">
        <div
            x-show="sidebarOpen && !isDesktop"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-black/50 dark:bg-black/70 z-30"
            @click="sidebarOpen = false"
        ></div>

        <aside
            x-show="isDesktop || sidebarOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="-translate-x-full rtl:translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full rtl:translate-x-full"
            class="fixed top-0 start-0 h-full w-64 bg-white dark:bg-[#161615] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] z-40 flex flex-col"
            :class="{ '-translate-x-full rtl:translate-x-full': !isDesktop && !sidebarOpen }"
        >
            <div class="p-6 pb-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-[#4A90D9] flex items-center justify-center">
                        <span class="text-white font-semibold text-sm">D</span>
                    </div>
                    <span class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Foodics</span>
                </div>
            </div>

            <nav class="flex-1 px-3 space-y-1">
                <a
                    href="{{ route('dashboard') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('dashboard') ? 'bg-[#F5F5F3] dark:bg-[#262625] text-[#1b1b18] dark:text-[#EDEDEC]' : 'text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    {{ __('Dashboard') }}
                </a>
                <a
                    href="{{ route('invoices') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('invoices') ? 'bg-[#F5F5F3] dark:bg-[#262625] text-[#1b1b18] dark:text-[#EDEDEC]' : 'text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    {{ __('Invoices') }}
                </a>
                <a
                    href="{{ route('products') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('products') ? 'bg-[#F5F5F3] dark:bg-[#262625] text-[#1b1b18] dark:text-[#EDEDEC]' : 'text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    {{ __('Products') }}
                </a>
                <a
                    href="{{ route('settings') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('settings') ? 'bg-[#F5F5F3] dark:bg-[#262625] text-[#1b1b18] dark:text-[#EDEDEC]' : 'text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ __('Settings') }}
                </a>
                <a
                    href="{{ route('contact') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs('contact') ? 'bg-[#F5F5F3] dark:bg-[#262625] text-[#1b1b18] dark:text-[#EDEDEC]' : 'text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                    {{ __('Contact Us') }}
                </a>
            </nav>

            <div class="px-3 py-4">
                <form method="POST" action="{{ route('language.switch') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button type="submit" class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] hover:bg-[#F5F5F3] dark:hover:bg-[#262625] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC] cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                        </svg>
                        {{ app()->getLocale() === 'ar' ? __('English') : __('العربية') }}
                    </button>
                </form>
            </div>

            <div class="p-4 border-t border-[#e3e3e0] dark:border-[#3E3E3A]">
                <div class="space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full {{ session('daftra_account') || auth()->user()?->hasDaftraConnection() ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                        <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ __('Daftra') }}</span>
                    </div>
                    @if(session('daftra_account') && is_array(session('daftra_account')))
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ session('daftra_account')['subdomain'] ?? '' }}</p>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ session('daftra_account')['business_name'] ?? '' }}</p>
                    @elseif(auth()->check())
                        @if(auth()->user()->daftraSubdomain())
                            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ auth()->user()->daftraSubdomain() }}</p>
                        @endif
                        @if(auth()->user()->daftraBusinessName())
                            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ auth()->user()->daftraBusinessName() }}</p>
                        @endif
                    @endif

                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full {{ session('foodics_account') || auth()->user()?->hasFoodicsConnection() ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                        <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ __('Foodics') }}</span>
                    </div>
                    @if(session('foodics_account') && is_array(session('foodics_account')))
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ session('foodics_account')['business_name'] ?? '' }}</p>
                    @elseif(auth()->check() && auth()->user()->foodicsBusinessName())
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] ps-4">{{ auth()->user()->foodicsBusinessName() }}</p>
                    @endif
                </div>
            </div>
        </aside>

        <div class="lg:ps-64">
            <header class="sticky top-0 z-20 bg-[#FDFDFC] dark:bg-[#0a0a0a] border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                <div class="flex lg:hidden items-center justify-between px-4 py-3">
<button
                            type="button"
                            class="p-2 -m-2 text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC] cursor-pointer"
                        >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <span class="font-medium text-sm">@yield('title', 'Dashboard')</span>
                </div>
            </header>

            <main class="p-6 lg:p-8">
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>