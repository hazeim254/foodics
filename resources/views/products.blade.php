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
                    <svg class="w-4 h-4 rtl:scale-x-[-1]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    {{ __('Sync Now') }}
                </button>
            </form>
        </template>
    </div>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    @if($products->count() > 0 || !empty(array_filter($filters ?? [])))
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 mb-4">
            <form method="GET" action="{{ route('products') }}" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Filters') }}</h3>
                    @if(!empty(array_filter($filters ?? [])))
                        <a href="{{ route('products') }}" class="inline-flex items-center gap-1.5 text-sm text-[#4A90D9] hover:underline">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            {{ __('Clear') }}
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 min-w-0">
                    <div class="min-w-0">
                        <label for="filter-search" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Search') }}</label>
                        <input id="filter-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                               placeholder="{{ __('Name or SKU') }}"
                               class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                    </div>

                    <div class="min-w-0">
                        <label for="filter-status" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Status') }}</label>
                        <select id="filter-status" name="status" class="w-full px-3 py-2 h-[38px] rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                            <option value="">{{ __('All') }}</option>
                            <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                            <option value="failed" {{ ($filters['status'] ?? '') === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                            <option value="synced" {{ ($filters['status'] ?? '') === 'synced' ? 'selected' : '' }}>{{ __('Synced') }}</option>
                        </select>
                    </div>

                    <div class="flex gap-2 min-w-0">
                        <div class="flex-1 min-w-0">
                            <label for="filter-price-from" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Price From') }}</label>
                            <input id="filter-price-from" type="number" name="price_from" value="{{ $filters['price_from'] ?? '' }}"
                                   step="0.01" min="0" placeholder="0"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                        <div class="flex-1 min-w-0">
                            <label for="filter-price-to" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('To') }}</label>
                            <input id="filter-price-to" type="number" name="price_to" value="{{ $filters['price_to'] ?? '' }}"
                                   step="0.01" min="0" placeholder="9999"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                    </div>

                    <div class="flex gap-2 min-w-0">
                        <div class="flex-1 min-w-0">
                            <label for="filter-date-from" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Date From') }}</label>
                            <input id="filter-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                        <div class="flex-1 min-w-0">
                            <label for="filter-date-to" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Date To') }}</label>
                            <input id="filter-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
                        </svg>
                        {{ __('Apply Filters') }}
                    </button>
                </div>
            </form>
        </div>

        @if(!empty(array_filter($filters ?? [])))
            <div class="flex flex-wrap gap-2 mb-4">
                @if(($filters['status'] ?? ''))
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Status') }}: {{ __($filters['status']) }}
                        <a href="{{ request()->fullUrlWithQuery(['status' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
                    </span>
                @endif
                @if(($filters['price_from'] ?? ''))
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Price') }}: {{ $filters['price_from'] }} - {{ $filters['price_to'] ?? str('&infin;')->toHtmlString() }}
                        <a href="{{ request()->fullUrlWithQuery(['price_from' => null, 'price_to' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
                    </span>
                @endif
                @if(($filters['date_from'] ?? ''))
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Date') }}: {{ $filters['date_from'] }} - {{ $filters['date_to'] ?? str('&infin;')->toHtmlString() }}
                        <a href="{{ request()->fullUrlWithQuery(['date_from' => null, 'date_to' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
                    </span>
                @endif
            </div>
        @endif
    @endif

    @if($products->count() > 0)
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="foodics_name" label="Name" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="foodics_sku" label="SKU" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="price" label="Price" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="daftra_id" label="Daftra ID" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="status" label="Status" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="created_at" label="Created" />
                        </th>
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
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">{{ $product->price ?? '—' }}</td>
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
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $product->status->badgeClasses() }}">{{ $product->status->label() }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#706f6c] dark:text-[#A1A09A]" title="{{ $product->created_at->toDateTimeString() }}">{{ $product->created_at->diffForHumans() }}</td>
                            <td class="px-6 py-4">
                                @if(in_array($product->status, [\App\Enums\ProductSyncStatus::Pending, \App\Enums\ProductSyncStatus::Failed]))
                                    <form method="POST" action="{{ route('products.resync', $product) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                                            <svg class="w-3.5 h-3.5 rtl:scale-x-[-1]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
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