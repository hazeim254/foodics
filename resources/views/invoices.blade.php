@extends('layouts.app')

@section('title', __('Invoices'))

@section('content')
<div class="max-w-7xl mx-auto" x-data="{ syncing: {{ $syncing ? 'true' : 'false' }} }" x-init="
    if (syncing) {
        const poll = setInterval(async () => {
            const res = await fetch('{{ route('invoices.sync-status') }}');
            const data = await res.json();
            if (!data.syncing) {
                clearInterval(poll);
                window.location.reload();
            }
        }, 3000);
    }
">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">{{ __('Invoices') }}</h1>
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
            <form method="POST" action="{{ route('invoices.sync') }}">
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

    @if($invoices->count() > 0 || !empty(array_filter($filters ?? [])))
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-4 mb-4">
            <form method="GET" action="{{ route('invoices') }}" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Filters') }}</h3>
                    @if(!empty(array_filter($filters ?? [])))
                        <a href="{{ route('invoices') }}" class="inline-flex items-center gap-1.5 text-sm text-[#4A90D9] hover:underline">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            {{ __('Clear') }}
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="filter-search" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Search') }}</label>
                        <input id="filter-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                               placeholder="{{ __('Foodics Ref or Daftra No') }}"
                               class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                    </div>

                    <div>
                        <label for="filter-status" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Status') }}</label>
                        <select id="filter-status" name="status" class="w-full px-3 py-2 h-[38px] rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                            <option value="">{{ __('All') }}</option>
                            <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                            <option value="failed" {{ ($filters['status'] ?? '') === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                            <option value="synced" {{ ($filters['status'] ?? '') === 'synced' ? 'selected' : '' }}>{{ __('Synced') }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="filter-type" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('Type') }}</label>
                        <select id="filter-type" name="type" class="w-full px-3 py-2 h-[38px] rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                            <option value="">{{ __('All') }}</option>
                            <option value="invoice" {{ ($filters['type'] ?? '') === 'invoice' ? 'selected' : '' }}>{{ __('Invoice') }}</option>
                            <option value="credit_note" {{ ($filters['type'] ?? '') === 'credit_note' ? 'selected' : '' }}>{{ __('Credit Note') }}</option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <div class="flex-1 min-w-0">
                            <label for="filter-amount-from" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('From') }}</label>
                            <input id="filter-amount-from" type="number" name="amount_from" value="{{ $filters['amount_from'] ?? '' }}"
                                   step="0.01" min="0" placeholder="0"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                        <div class="flex-1 min-w-0">
                            <label for="filter-amount-to" class="block text-xs text-[#706f6c] dark:text-[#A1A09A] mb-1">{{ __('To') }}</label>
                            <input id="filter-amount-to" type="number" name="amount_to" value="{{ $filters['amount_to'] ?? '' }}"
                                   step="0.01" min="0" placeholder="9999"
                                   class="w-full px-3 py-2 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] text-sm">
                        </div>
                    </div>

                    <div class="flex gap-2">
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
                @if(($filters['type'] ?? ''))
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Type') }}: {{ __($filters['type']) }}
                        <a href="{{ request()->fullUrlWithQuery(['type' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
                    </span>
                @endif
                @if(($filters['amount_from'] ?? ''))
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[#F5F5F3] dark:bg-[#262625] text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Amount') }}: {{ $filters['amount_from'] }} - {{ $filters['amount_to'] ?? str('&infin;')->toHtmlString() }}
                        <a href="{{ request()->fullUrlWithQuery(['amount_from' => null, 'amount_to' => null]) }}" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">&times;</a>
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

    @if($invoices->count() > 0)
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="foodics_reference" label="Foodics Ref" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="daftra_no" label="Daftra Invoice" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="total_price" label="Total" />
                        </th>
                        <th class="px-6 py-3 text-start text-xs font-medium text-[#706f6c] dark:text-[#A1A09A] uppercase tracking-wider">
                            <x-sortable-link column="type" label="Type" />
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
                    @foreach($invoices as $invoice)
                        <tr class="hover:bg-[#F5F5F3] dark:hover:bg-[#262625] transition-colors">
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                <a href="{{ config('services.foodics.base_url') }}/orders/{{ $invoice->foodics_id }}" target="_blank" class="hover:underline">
                                    {{ $invoice->foodics_reference }}
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                @php
                                    $daftraNo = $invoice->daftra_no ?? $invoice->daftra_id;
                                    $daftraSubdomain = auth()->user()?->daftra_meta['subdomain'] ?? null;
                                @endphp

                                @if($daftraSubdomain && $invoice->daftra_id)
                                    <a href="https://{{ $daftraSubdomain }}/owner/invoices/view/{{ $invoice->daftra_id }}" target="_blank" class="hover:underline">
                                        {{ $daftraNo }}
                                    </a>
                                @else
                                    {{ $daftraNo ?? '—' }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                {{ $invoice->total_price ?? '—' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                                {{ $invoice->type->label() }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $invoice->status->badgeClasses() }}">{{ $invoice->status->label() }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#706f6c] dark:text-[#A1A09A]" title="{{$invoice->created_at->toDateTimeString()}}">{{ $invoice->created_at->diffForHumans() }}</td>
                            <td class="px-6 py-4">
                                @if(in_array($invoice->status, [\App\Enums\InvoiceSyncStatus::Pending, \App\Enums\InvoiceSyncStatus::Failed]))
                                    <form method="POST" action="{{ route('invoices.retry-sync', $invoice) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                                            <svg class="w-3.5 h-3.5 rtl:scale-x-[-1]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.032 9.035a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                            </svg>
                                            {{ __('Retry Sync') }}
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
            {{ $invoices->withQueryString()->links('pagination::tailwind-custom') }}
        </div>
    @else
        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6">
            <p class="text-[#706f6c] dark:text-[#A1A09A]">{{ __('No invoices yet.') }}</p>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mt-1">{{ __('Sync your Foodics orders to see them here.') }}</p>
        </div>
    @endif
</div>
@endsection
