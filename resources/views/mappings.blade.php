@extends('layouts.app')

@section('title', __('Mappings'))

@section('content')
<div class="max-w-4xl mx-auto" x-data="{ saving: false }">
    <h1 class="text-2xl font-semibold mb-6">{{ __('Mappings') }}</h1>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    <div class="bg-surface-1 rounded-lg shadow-sm border border-line p-6 card-accent mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium text-ink">{{ __('Branch Mappings') }}</h2>
            <form method="POST" action="{{ route('mappings.branches.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ __('Sync Branches') }}
                </button>
            </form>
        </div>

        @if(!empty($foodicsBranches))
            @if($daftraBranchesDisabled)
                <x-alert type="info">
                    {{ __('Daftra branches plugin is not enabled. All Foodics branches will sync to the single default Daftra branch. You can configure the default branch in Settings.') }}
                </x-alert>
            @else
                <form method="POST" action="{{ route('mappings.branches.store') }}" @submit="saving = true">
                    @csrf
                    <div class="space-y-3">
                        @foreach($foodicsBranches as $branch)
                            <div class="flex items-center gap-4 py-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-ink truncate">{{ $branch['name'] }}</p>
                                    <p class="text-xs text-ink-muted">{{ $branch['reference'] ?? '' }}</p>
                                    <input type="hidden" name="mappings[{{ $loop->index }}][foodics_id]" value="{{ $branch['id'] }}">
                                </div>
                                <div class="w-48">
                                    <select
                                        name="mappings[{{ $loop->index }}][daftra_id]"
                                        class="w-full rounded-lg border border-line bg-surface-input text-ink px-3 py-2 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                                    >
                                        <option value="">{{ __('-- Not mapped --') }}</option>
                                        @foreach($daftraBranches as $daftraBranch)
                                            <option value="{{ $daftraBranch['id'] }}" {{ ($branchMappings[$branch['id']] ?? null)?->daftra_id == $daftraBranch['id'] ? 'selected' : '' }}>
                                                {{ $daftraBranch['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-4">
                        <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow disabled:opacity-60 disabled:cursor-not-allowed">
                            {{ __('Save Branch Mappings') }}
                        </button>
                    </div>
                </form>
            @endif
        @else
            <p class="text-sm text-ink-muted">{{ __('Click "Sync Branches" to fetch branches from Foodics and Daftra.') }}</p>
        @endif

        @if($branchMappings->isNotEmpty() && empty($foodicsBranches))
            <div class="mt-4 pt-4 border-t border-line">
                <h3 class="text-sm font-medium text-ink mb-2">{{ __('Saved Branch Mappings') }}</h3>
                <div class="space-y-2">
                    @foreach($branchMappings as $mapping)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-ink-muted">{{ $mapping->foodics_id }}</span>
                            <svg class="w-3 h-3 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            <span class="text-ink">{{ __('Daftra Branch') }} #{{ $mapping->daftra_id }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="bg-surface-1 rounded-lg shadow-sm border border-line p-6 card-accent">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium text-ink">{{ __('Tax Mappings') }}</h2>
            <form method="POST" action="{{ route('mappings.taxes.sync') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ __('Sync Taxes') }}
                </button>
            </form>
        </div>

        @if(!empty($foodicsTaxes))
            <p class="text-xs text-ink-muted mb-3">{{ __('Tax auto-matching is active: taxes are automatically matched by name and rate. Use manual mapping below to override.') }}</p>
            <form method="POST" action="{{ route('mappings.taxes.store') }}" @submit="saving = true">
                @csrf
                <div class="space-y-3">
                    @foreach($foodicsTaxes as $tax)
                        <div class="flex items-center gap-4 py-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-ink truncate">{{ $tax['name'] }} ({{ $tax['rate'] }}%)</p>
                                <input type="hidden" name="mappings[{{ $loop->index }}][foodics_id]" value="{{ $tax['id'] }}">
                            </div>
                            <div class="w-48">
                                <select
                                    name="mappings[{{ $loop->index }}][daftra_id]"
                                    class="w-full rounded-lg border border-line bg-surface-input text-ink px-3 py-2 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                                >
                                    <option value="">{{ __('-- Auto-match --') }}</option>
                                    @foreach($daftraTaxes as $daftraTax)
                                        <option value="{{ $daftraTax['id'] }}" {{ ($taxMappings[$tax['id']] ?? null)?->daftra_id == $daftraTax['id'] ? 'selected' : '' }}>
                                            {{ $daftraTax['name'] }} ({{ $daftraTax['value'] }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow disabled:opacity-60 disabled:cursor-not-allowed">
                        {{ __('Save Tax Mappings') }}
                    </button>
                </div>
            </form>
        @else
            <p class="text-sm text-ink-muted">{{ __('Click "Sync Taxes" to fetch taxes from Foodics and Daftra.') }}</p>
        @endif

        @if($taxMappings->isNotEmpty() && empty($foodicsTaxes))
            <div class="mt-4 pt-4 border-t border-line">
                <h3 class="text-sm font-medium text-ink mb-2">{{ __('Saved Tax Mappings') }}</h3>
                <div class="space-y-2">
                    @foreach($taxMappings as $mapping)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-ink-muted">{{ $mapping->foodics_id }}</span>
                            <svg class="w-3 h-3 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            <span class="text-ink">{{ __('Daftra Tax') }} #{{ $mapping->daftra_id }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection