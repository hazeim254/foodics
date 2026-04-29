@extends('layouts.app')

@section('title', __('Products'))

@section('content')
<div class="max-w-7xl mx-auto" x-data="{ syncing: {{ $syncing ? 'true' : 'false' }} }" x-init="
    if (syncing) {
        const poll = setInterval(async () => {
            const res = await fetch('{{ route('products.sync-status') }}');
            const data = await res.json();
            if (!data.syncing) {
                clearInterval(poll);
                window.location.reload();
            }
        }, 3000);
    }
">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">{{ __('Products') }}</h1>
        <template x-if="syncing">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                {{ __('Syncing…') }}
            </span>
        </template>
        <template x-if="!syncing">
            <form method="POST" action="{{ route('products.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    {{ __('Sync Now') }}
                </button>
            </form>
        </template>
    </div>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    @if($products->count() > 0)
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Name') }}</th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('SKU') }}</th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Daftra ID') }}</th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Created') }}</th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    @foreach($products as $product)
                        <tr class="hover:bg-[#F5F5F3] dark:hover:bg-[#262625] transition-colors">
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                <a href="{{ config('services.foodics.base_url') }}/menu/products/{{ $product->foodics_id }}" target="_blank" class="hover:underline">
                                    {{ $product->foodics_name }}
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">{{ $product->foodics_sku ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                @php
                                    $daftraSubdomain = auth()->user()?->daftra_meta['subdomain'] ?? null;
                                @endphp

                                @if($daftraSubdomain && $product->daftra_id)
                                    <a href="https://{{ $daftraSubdomain }}/owner/products/view/{{ $product->daftra_id }}" target="_blank" class="hover:underline">
                                        {{ $product->daftra_id }}
                                    </a>
                                @else
                                    {{ $product->daftra_id ?? '—' }}
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $product->status->badgeClasses() }}">{{ ucfirst($product->status->value) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#706f6c] dark:text-[#A1A09A]" title="{{ $product->created_at->toDateTimeString() }}">{{ $product->created_at->diffForHumans() }}</td>
                            <td class="px-6 py-4">
                                @if(in_array($product->status, [\App\Enums\ProductSyncStatus::Pending, \App\Enums\ProductSyncStatus::Failed]))
                                    <form method="POST" action="{{ route('products.resync', $product) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                            </svg>
                                            {{ __('Resync') }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $products->withQueryString()->links('pagination::tailwind-custom') }}
        </div>
    @else
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6">
            <p class="text-[#706f6c] dark:text-[#A1A09A]">{{ __('No products yet.') }}</p>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mt-1">{{ __('Sync your Foodics products to see them here.') }}</p>
        </div>
    @endif
</div>
@endsection
