<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr' }}" style="--en-font: '{{ $enFont }}'; --ar-font: '{{ $arFont }}';">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('Daftrics — Foodics sales land in Daftra, automatically') }}</title>
        <meta name="description" content="{{ __('The Foodics × Daftra integration that turns every sale, return, and product into an up-to-date Daftra record, automatically.') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cairo:wght@400;500;600;700&family=Instrument+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Noto+Sans:wght@400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-surface-0 text-ink antialiased">

        {{-- Top bar --}}
        <header class="site-header sticky top-0 z-30 bg-surface-0/85 backdrop-blur-md border-b border-line/60">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <a href="{{ route('landing') }}" class="flex items-center gap-2.5 focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 rounded-lg">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent">
                        <span class="text-on-accent font-semibold text-sm">D</span>
                    </span>
                    <span class="font-semibold text-ink">{{ config('app.name') }}</span>
                </a>

                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-sm font-medium text-ink-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 rounded-md px-2 py-1">
                            {{ __('Dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-medium text-ink-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 rounded-md px-2 py-1">
                            {{ __('Sign in') }}
                        </a>
                    @endauth

                    <form method="POST" action="{{ route('language.switch') }}" class="inline-flex">
                        @csrf
                        <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                        <button type="submit" class="text-sm font-medium text-ink-muted hover:text-ink bg-surface-1/85 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-line cursor-pointer focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0">
                            {{ app()->getLocale() === 'ar' ? __('English') : __('العربية') }}
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main>

            {{-- Hero --}}
            <section class="landing-hero page-content">
                <div class="mx-auto max-w-6xl px-6 py-20 lg:py-28">
                <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-accent">
                            {{ __('Foodics × Daftra') }}
                        </p>

                        <h1 class="mt-5 font-semibold text-ink leading-[1.1]" style="font-size: clamp(2.25rem, 4vw, 3.5rem);">
                            <span class="block">{{ __('Your Foodics sales land in Daftra.') }}</span>
                            <span class="block text-accent">
                                <span class="hero-accent-word inline-block">{{ __('Automatically.') }}</span>
                            </span>
                        </h1>

                        <p class="mt-6 text-lg text-ink leading-relaxed max-w-[60ch]">
                            {{ __('Stop double-entering invoices. Connect both accounts once, and every sale, return, and product flows into Daftra on its own.') }}
                        </p>
                        <p class="mt-2 text-sm text-accent/70">
                            {{ __('ZATCA compliance included.') }}
                        </p>

                        <div class="mt-8 flex flex-col items-start gap-3">
                            @auth
                                <a href="{{ route('dashboard') }}"
                                   data-magnetic
                                   class="btn-shadow inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent px-6 py-3.5 text-base font-semibold text-on-accent transition-all hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 sm:w-auto">
                                    <span>{{ __('Dashboard') }}</span>
                                    <svg class="cta-arrow h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M5 12l14 0" />
                                        <path d="M13 18l6 -6" />
                                        <path d="M13 6l6 6" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                   data-magnetic
                                   class="btn-shadow inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent px-6 py-3.5 text-base font-semibold text-on-accent transition-all hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 sm:w-auto">
                                    <span>{{ __('Connect your accounts') }}</span>
                                    <svg class="cta-arrow h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M5 12l14 0" />
                                        <path d="M13 18l6 -6" />
                                        <path d="M13 6l6 6" />
                                    </svg>
                                </a>
                            @endauth

<a href="#how-it-works" class="text-sm text-accent/70 hover:text-accent focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 rounded-md px-1 py-0.5">
                            {{ __('See how it works') }}
                        </a>
                        </div>
                    </div>

                    {{-- Live sync panel --}}
                    @php
                        $syncRows = [
                            ['from' => 'Order', 'from_id' => '#4471', 'to' => 'Invoice', 'to_id' => '#2891'],
                            ['from' => 'Product', 'from_id' => '#1024', 'to' => 'matched', 'to_id' => null],
                            ['from' => 'Refund', 'from_id' => '#298', 'to' => 'Credit Note', 'to_id' => '#99'],
                            ['from' => 'Order', 'from_id' => '#4472', 'to' => 'Invoice', 'to_id' => '#2892'],
                            ['from' => 'Product', 'from_id' => '#1025', 'to' => 'matched', 'to_id' => null],
                            ['from' => 'Order', 'from_id' => '#4473', 'to' => 'Invoice', 'to_id' => '#2893'],
                            ['from' => 'Refund', 'from_id' => '#299', 'to' => 'Credit Note', 'to_id' => '#100'],
                            ['from' => 'Order', 'from_id' => '#4474', 'to' => 'Invoice', 'to_id' => '#2894'],
                        ];
                    @endphp
                    <div class="flex items-center justify-center">
                        <div class="sync-panel w-full max-w-[440px] overflow-hidden rounded-2xl border border-line/70 bg-surface-1 shadow-[0_1px_3px_color-mix(in_oklch,var(--ink)_6%,transparent)]" role="region" aria-label="{{ __('Live sync') }}">
                            {{-- Panel header --}}
                            <div class="flex items-center justify-between gap-3 border-b border-line/50 bg-accent/[0.03] px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="dot-connected inline-block h-1.5 w-1.5 rounded-full bg-tone-success" aria-hidden="true"></span>
                                    <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{{ __('Live sync') }}</span>
                                </div>
                                <span class="text-[10px] font-medium tracking-wide text-ink-muted">
                                    {{ __('Foodics') }}
                                    <span class="sync-arrow px-1 text-ink-muted/70">→</span>
                                    {{ __('Daftra') }}
                                </span>
                            </div>

                            {{-- Scrolling log --}}
                            <div class="relative h-44 overflow-hidden">
                                <ul class="sync-log absolute inset-x-0 top-0 m-0 list-none">
                                    @foreach (array_merge($syncRows, $syncRows) as $row)
                                        <li class="flex items-center gap-2.5 px-4 py-2.5 text-[12.5px] tabular-nums">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-tone-success" aria-hidden="true"></span>
                                            <span class="shrink-0 sync-row-from">{{ __($row['from']) }}</span>
                                            <span class="shrink-0 sync-row-id font-medium">{{ $row['from_id'] }}</span>
                                            <span class="sync-arrow shrink-0 px-1 text-ink-muted/50">→</span>
                                            <span class="shrink-0 sync-row-to">{{ __($row['to']) }}</span>
                                            @if ($row['to_id'])
                                                <span class="shrink-0 sync-row-id font-medium">{{ $row['to_id'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="pointer-events-none absolute inset-x-0 top-0 h-7 bg-gradient-to-b from-surface-1 to-transparent" aria-hidden="true"></div>
                                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-7 bg-gradient-to-t from-surface-1 to-transparent" aria-hidden="true"></div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </section>

            {{-- What's automatic --}}
            <section class="landing-section-soft scroll-reveal border-t border-line/60 py-16" aria-labelledby="whats-automatic-heading">
                <div class="mx-auto max-w-6xl px-6">
                    <h2 id="whats-automatic-heading" class="text-2xl font-semibold text-ink">{{ __("What's automatic") }}</h2>

                    <div class="mt-10 space-y-4">
                        {{-- Band 01 --}}
                        <div class="band-row flex flex-col gap-4 rounded-2xl bg-surface-0 px-6 py-7 border-b-2 border-accent/40 sm:flex-row sm:items-center sm:gap-8 card-stagger" style="--stagger: 0;">
                            <div class="band-num shrink-0 text-4xl font-semibold text-accent/70 leading-none tabular-nums">01</div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-ink">{{ __('Sales become invoices') }}</h3>
                                <p class="mt-2 text-base text-ink-muted leading-relaxed max-w-[65ch]">
                                    {{ __('Every Foodics order lands in Daftra as an invoice within seconds, with customer details attached.') }}
                                </p>
                            </div>
                            <div class="band-icon-chip icon-chip hidden sm:flex shrink-0 h-14 w-14 items-center justify-center rounded-xl">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                                    <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" />
                                    <path d="M9 7l1 0" />
                                    <path d="M9 13l6 0" />
                                    <path d="M13 17l2 0" />
                                </svg>
                            </div>
                        </div>

                        {{-- Band 02 --}}
                        <div class="band-row band-row-alt flex flex-col gap-4 rounded-2xl px-6 py-8 sm:flex-row sm:items-center sm:gap-8 card-stagger" style="--stagger: 1;">
                            <div class="band-num shrink-0 text-4xl font-semibold text-accent/70 leading-none tabular-nums">02</div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-ink">{{ __('Products stay matched') }}</h3>
                                <p class="mt-2 text-base text-ink-muted leading-relaxed max-w-[65ch]">
                                    {{ __('Your Foodics catalogue mirrors into Daftra, so every invoice line item matches the product that sold.') }}
                                </p>
                            </div>
                            <div class="band-icon-chip icon-chip hidden sm:flex shrink-0 h-14 w-14 items-center justify-center rounded-xl">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M7 10h14l-4 -4" />
                                    <path d="M17 14h-14l4 4" />
                                </svg>
                            </div>
                        </div>

                        {{-- Band 03 --}}
                        <div class="band-row flex flex-col gap-4 rounded-2xl bg-surface-0 px-6 py-7 border-t-2 border-accent/40 sm:flex-row sm:items-center sm:gap-8 card-stagger" style="--stagger: 2;">
                            <div class="band-num shrink-0 text-4xl font-semibold text-accent/70 leading-none tabular-nums">03</div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-ink">{{ __('Returns become credit notes') }}</h3>
                                <p class="mt-2 text-base text-ink-muted leading-relaxed max-w-[65ch]">
                                    {{ __('Cancelled and returned orders turn into Daftra credit notes. No manual cleanup.') }}
                                </p>
                            </div>
                            <div class="band-icon-chip icon-chip hidden sm:flex shrink-0 h-14 w-14 items-center justify-center rounded-xl">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3.06 13a9 9 0 1 0 .49 -4.087" />
                                    <path d="M3 4.001v5h5" />
                                    <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" fill="currentColor" stroke="none" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- How it works --}}
            <section id="how-it-works" class="landing-section-alt scroll-reveal border-t border-line/60 py-20" aria-labelledby="how-it-works-heading">
                <div class="mx-auto max-w-3xl px-6">
                    <h2 id="how-it-works-heading" class="text-2xl font-semibold text-ink">{{ __('How it works') }}</h2>

                    <ol class="mt-10 space-y-6">
                        <li class="step-connector step-row flex items-start gap-4 card-stagger" style="--stagger: 0;">
                            <span class="step-num flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent text-xs font-semibold tabular-nums mt-0.5">1</span>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="step-title text-base font-semibold text-ink">{{ __('Connect Daftra') }}</h3>
                                    <span class="inline-flex items-center justify-center rounded-md bg-brand-daftra px-2 py-1">
                                        <img src="https://www.daftra.com/themed/multi_language/images/logos/daftra-ar.svg" alt="Daftra" class="h-3 w-auto" style="filter: brightness(0) invert(1);">
                                    </span>
                                </div>
                                <p class="mt-1.5 text-sm text-ink-muted">{{ __('Authorise your Daftra account in one click.') }}</p>
                            </div>
                        </li>

                        <li class="step-connector step-row flex items-start gap-4 card-stagger" style="--stagger: 1;">
                            <span class="step-num flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent text-xs font-semibold tabular-nums mt-0.5">2</span>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="step-title text-base font-semibold text-ink">{{ __('Connect Foodics') }}</h3>
                                    <span class="inline-flex items-center justify-center rounded-md bg-brand-foodics px-2 py-1">
                                        <img src="https://www.foodics.com/wp-content/uploads/2021/12/foodics-logo.svg" alt="Foodics" class="h-3 w-auto" style="filter: brightness(0) invert(1);">
                                    </span>
                                </div>
                                <p class="mt-1.5 text-sm text-ink-muted">{{ __('Authorise your Foodics business in one click.') }}</p>
                            </div>
                        </li>

                        <li class="step-connector step-row flex items-start gap-4 card-stagger" style="--stagger: 2;">
                            <span class="step-num flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent text-xs font-semibold tabular-nums mt-0.5">3</span>
                            <div class="flex-1 min-w-0">
                                <h3 class="step-title text-base font-semibold text-ink">{{ __('Carry on running your restaurant') }}</h3>
                                <p class="mt-1.5 text-sm text-ink-muted">{{ __('Daftrics keeps both systems in sync, quietly.') }}</p>
                            </div>
                        </li>
                    </ol>

                    <div class="mt-12 flex justify-start">
                        @auth
                            <a href="{{ route('dashboard') }}"
                               data-magnetic
                               class="btn-shadow inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent px-6 py-3.5 text-base font-semibold text-on-accent transition-all hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 sm:w-auto">
                                <span>{{ __('Dashboard') }}</span>
                                <svg class="cta-arrow h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12l14 0" />
                                    <path d="M13 18l6 -6" />
                                    <path d="M13 6l6 6" />
                                </svg>
                            </a>
                        @else
                            <a href="{{ route('login') }}"
                               data-magnetic
                               class="btn-shadow inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent px-6 py-3.5 text-base font-semibold text-on-accent transition-all hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0 sm:w-auto">
                                <span>{{ __('Connect your accounts') }}</span>
                                <svg class="cta-arrow h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12l14 0" />
                                    <path d="M13 18l6 -6" />
                                    <path d="M13 6l6 6" />
                                </svg>
                            </a>
                        @endauth
                    </div>
                </div>
            </section>

            {{-- Reliability strip --}}
            <section class="scroll-reveal border-t border-line/60 py-10">
                <div class="mx-auto max-w-6xl px-6">
                    <ul class="grid grid-cols-1 gap-y-3 gap-x-8 text-sm text-ink-muted sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-4">
                        <li class="reliability-item flex items-start gap-3 card-stagger" style="--stagger: 0;">
                            <span class="icon-chip mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11" />
                                </svg>
                            </span>
                            <span class="reliability-label">{{ __('Syncs within seconds of every sale.') }}</span>
                        </li>
                        <li class="reliability-item flex items-start gap-3 card-stagger" style="--stagger: 1;">
                            <span class="icon-chip mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                    <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                                </svg>
                            </span>
                            <span class="reliability-label">{{ __('Failed syncs retry on their own.') }}</span>
                        </li>
                        <li class="reliability-item flex items-start gap-3 card-stagger" style="--stagger: 2;">
                            <span class="icon-chip mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M13 5h8" />
                                    <path d="M13 9h5" />
                                    <path d="M13 15h8" />
                                    <path d="M13 19h5" />
                                    <path d="M3 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" />
                                    <path d="M3 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" />
                                </svg>
                            </span>
                            <span class="reliability-label">{{ __('A full audit trail for every invoice.') }}</span>
                        </li>
                        <li class="reliability-item flex items-start gap-3 card-stagger" style="--stagger: 3;">
                            <span class="icon-chip mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06" />
                                    <path d="M15 19l2 2l4 -4" />
                                </svg>
                            </span>
                            <span class="reliability-label">{{ __('Every ZATCA phase, cleared in Daftra.') }}</span>
                        </li>
                    </ul>
                </div>
            </section>

        </main>

        {{-- Footer --}}
        <footer class="landing-footer py-8">
            <div class="mx-auto max-w-6xl px-6">
                <div class="landing-divider h-px"></div>
                <div class="mt-6 flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-sm font-semibold text-ink">{{ config('app.name') }}</span>
                    <p class="text-xs text-ink-muted">&copy; {{ now()->year }} {{ config('app.name') }}</p>
                    <form method="POST" action="{{ route('language.switch') }}" class="inline-flex">
                        @csrf
                        <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                        <button type="submit" class="text-sm font-medium text-ink-muted hover:text-ink bg-surface-1/85 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-line cursor-pointer focus:outline-none focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0">
                            {{ app()->getLocale() === 'ar' ? __('English') : __('العربية') }}
                        </button>
                    </form>
                </div>
            </div>
        </footer>

        <script>
            (() => {
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (reduced) {
                    document.querySelectorAll('.scroll-reveal').forEach((el) => {
                        el.classList.add('is-visible');
                    });
                } else {
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach((entry) => {
                            if (entry.isIntersecting) {
                                entry.target.classList.add('is-visible');
                                observer.unobserve(entry.target);
                            }
                        });
                    }, { threshold: 0.1 });

                    document.querySelectorAll('.scroll-reveal').forEach((el) => {
                        observer.observe(el);
                    });
                }

                const header = document.querySelector('.site-header');
                if (header) {
                    const onScroll = () => {
                        header.classList.toggle('is-scrolled', window.scrollY > 80);
                    };
                    window.addEventListener('scroll', onScroll, { passive: true });
                    onScroll();
                }
            })();
        </script>

        <script>
            (() => {
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const hoverable = window.matchMedia('(hover: hover)').matches;
                if (reduced || !hoverable) {
                    return;
                }

                document.querySelectorAll('[data-magnetic]').forEach((el) => {
                    let raf = 0;
                    el.addEventListener('mousemove', (event) => {
                        const rect = el.getBoundingClientRect();
                        const offsetX = event.clientX - rect.left - rect.width / 2;
                        const offsetY = event.clientY - rect.top - rect.height / 2;
                        cancelAnimationFrame(raf);
                        raf = requestAnimationFrame(() => {
                            el.style.setProperty('--mag-x', `${(offsetX * 0.12).toFixed(2)}px`);
                            el.style.setProperty('--mag-y', `${(offsetY * 0.12).toFixed(2)}px`);
                        });
                    });
                    el.addEventListener('mouseleave', () => {
                        cancelAnimationFrame(raf);
                        el.style.setProperty('--mag-x', '0px');
                        el.style.setProperty('--mag-y', '0px');
                    });
                });
            })();
        </script>

        <script>
            console.log(
                '%cDaftrics',
                'font: 600 28px/1 Inter, system-ui, sans-serif; color: oklch(56% 0.118 239); padding: 6px 0;'
            );
            console.log(
                '%cFoodics × Daftra, syncing quietly.',
                'font: 13px/1.5 Inter, system-ui, sans-serif; color: oklch(50% 0.02 94);'
            );
        </script>

    </body>
</html>
