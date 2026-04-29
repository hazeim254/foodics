@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
<div class="max-w-7xl mx-auto">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Dashboard') }}</h1>
        <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ __('Foodics to Daftra sync health at a glance.') }}</p>
    </div>

    {{-- Invoice Sync Summary --}}
    <div class="mb-6">
        <h2 class="text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-3">{{ __('Invoice Sync') }}</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Total Invoices') }}</p>
                <p class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC] mt-1">{{ $invoiceStats['total'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Synced') }}</p>
                <p class="text-2xl font-semibold text-green-700 dark:text-green-400 mt-1">{{ $invoiceStats['synced'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Failed') }}</p>
                <p class="text-2xl font-semibold text-red-700 dark:text-red-400 mt-1">{{ $invoiceStats['failed'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Pending') }}</p>
                <p class="text-2xl font-semibold text-amber-700 dark:text-amber-400 mt-1">{{ $invoiceStats['pending'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Success Rate') }}</p>
                <p class="text-2xl font-semibold text-[#4A90D9] mt-1">{{ $invoiceStats['success_rate'] }}%</p>
            </div>
        </div>
    </div>

    {{-- Product Sync Summary --}}
    <div class="mb-6">
        <h2 class="text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-3">{{ __('Product Sync') }}</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Total Products') }}</p>
                <p class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC] mt-1">{{ $productStats['total'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Synced') }}</p>
                <p class="text-2xl font-semibold text-green-700 dark:text-green-400 mt-1">{{ $productStats['synced'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Failed') }}</p>
                <p class="text-2xl font-semibold text-red-700 dark:text-red-400 mt-1">{{ $productStats['failed'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Pending') }}</p>
                <p class="text-2xl font-semibold text-amber-700 dark:text-amber-400 mt-1">{{ $productStats['pending'] }}</p>
            </div>
            <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 card-accent">
                <p class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Success Rate') }}</p>
                <p class="text-2xl font-semibold text-[#4A90D9] mt-1">{{ $productStats['success_rate'] }}%</p>
            </div>
        </div>
    </div>

    {{-- Sync Over Time Chart --}}
    <div class="mb-6">
        <h2 class="text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-3">{{ __('Sync Over Time') }}</h2>
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6 card-accent">
            @php
                $maxValue = max(
                    collect($syncOverTime['invoices']['synced'])->max(),
                    collect($syncOverTime['invoices']['failed'])->max(),
                    collect($syncOverTime['products']['synced'])->max(),
                    collect($syncOverTime['products']['failed'])->max(),
                    1
                );
            @endphp
            <div class="flex flex-col">
                {{-- Legend --}}
                <div class="flex flex-wrap gap-4 mb-4 text-xs">
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-green-500 inline-block"></span>
                        {{ __('Invoice Synced') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-red-500 inline-block"></span>
                        {{ __('Invoice Failed') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-green-300 inline-block"></span>
                        {{ __('Product Synced') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-red-300 inline-block"></span>
                        {{ __('Product Failed') }}
                    </span>
                </div>

                {{-- Chart --}}
                <div class="overflow-x-auto">
                    @php
                        $barHeight = fn ($value) => $maxValue > 0 ? max(($value / $maxValue) * 100, 2) : 2;
                    @endphp
                    <div class="flex items-end gap-2 min-w-[420px] h-48">
                        @foreach($syncOverTime['labels'] as $i => $label)
                            <div class="flex-1 flex flex-col items-center h-full justify-end">
                                <div class="w-full flex items-end justify-center gap-0.5 flex-1">
                                    <div class="w-2.5 bg-green-500 rounded-t-sm transition-all duration-300" style="height: {{ $barHeight($syncOverTime['invoices']['synced'][$i]) }}%;" title="{{ __('Invoice Synced') }}: {{ $syncOverTime['invoices']['synced'][$i] }}"></div>
                                    <div class="w-2.5 bg-red-500 rounded-t-sm transition-all duration-300" style="height: {{ $barHeight($syncOverTime['invoices']['failed'][$i]) }}%;" title="{{ __('Invoice Failed') }}: {{ $syncOverTime['invoices']['failed'][$i] }}"></div>
                                    <div class="w-2.5 bg-green-300 rounded-t-sm transition-all duration-300" style="height: {{ $barHeight($syncOverTime['products']['synced'][$i]) }}%;" title="{{ __('Product Synced') }}: {{ $syncOverTime['products']['synced'][$i] }}"></div>
                                    <div class="w-2.5 bg-red-300 rounded-t-sm transition-all duration-300" style="height: {{ $barHeight($syncOverTime['products']['failed'][$i]) }}%;" title="{{ __('Product Failed') }}: {{ $syncOverTime['products']['failed'][$i] }}"></div>
                                </div>
                                <span class="text-[10px] text-[#706f6c] dark:text-[#A1A09A] mt-1.5 whitespace-nowrap">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Default Settings --}}
    <div>
        <h2 class="text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-3">{{ __('Default Settings') }}</h2>
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6 card-accent">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Default Client') }}</p>
                        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $defaultSettings['client_id'] ?? __('Not configured') }}</p>
                    </div>
                </div>
                <div class="h-px bg-[#e3e3e0] dark:bg-[#3E3E3A]"></div>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Default Branch') }}</p>
                        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $defaultSettings['branch_id'] ?? __('Default branch (1)') }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <a href="{{ route('settings') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-all duration-200 btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ __('Update Settings') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection