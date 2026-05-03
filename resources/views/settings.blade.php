@extends('layouts.app')

@section('title', __('Settings'))

@section('content')
<div class="max-w-3xl mx-auto" x-data="{ saving: false }">
    <h1 class="text-2xl font-semibold mb-6">{{ __('Settings') }}</h1>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    <form @submit="saving = true" method="POST" action="{{ route('settings.update') }}">
        @csrf

        <div class="bg-surface-1 rounded-lg shadow-sm border border-line p-6 card-accent">
            <h2 class="text-lg font-medium text-ink mb-4">{{ __('Daftra Integration') }}</h2>

            @if($branches !== null)
                <div class="mb-4">
                    <label for="daftra_default_branch_id" class="block text-sm font-medium text-ink mb-1">{{ __('Default Branch') }}</label>

                    <select
                        id="daftra_default_branch_id"
                        name="daftra_default_branch_id"
                        class="w-full rounded-lg border border-line bg-surface-input text-ink px-4 py-2.5 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                    >
                        @foreach($branches as $branch)
                            <option value="{{ $branch['id'] }}" {{ old('daftra_default_branch_id', $daftraDefaultBranchId ?? '') == $branch['id'] ? 'selected' : '' }}>
                                {{ $branch['name'] }}
                            </option>
                        @endforeach
                    </select>

                    @error('daftra_default_branch_id')
                        <p class="mt-1 text-xs text-tone-danger">{{ $message }}</p>
                    @enderror

                    <p class="mt-1 text-xs text-ink-muted">
                        {{ __('Daftra branch used for all API requests.') }}
                    </p>
                </div>
            @endif

            <div
                x-data="{
                    selectedId: @js($daftraDefaultClientId ?? ''),
                    selectedName: @js($daftraDefaultClient['name'] ?? ''),
                    selectedAvatar: @js($daftraDefaultClient['avatar'] ?? ''),
                    query: '',
                    results: [],
                    loading: false,
                    open: false,
                    error: false,
                    noResultsText: @js(__('No clients found.')),
                    minCharsText: @js(__('Type at least 2 characters to search…')),
                    searchFailedText: @js(__('Search failed. Try again.')),
                    search() {
                        if (this.query.length < 2) {
                            this.results = [];
                            this.open = false;
                            return;
                        }
                        this.loading = true;
                        fetch(`/settings/search-clients?query=${encodeURIComponent(this.query)}`)
                            .then(res => res.json())
                            .then(data => {
                                this.error = false;
                                this.results = data.data;
                                this.open = true;
                            })
                            .catch(() => {
                                this.error = true;
                                this.results = [];
                                this.open = true;
                            })
                            .finally(() => {
                                this.loading = false;
                            });
                    }
                }"
                class="mb-4"
            >
                <label class="block text-sm font-medium text-ink mb-1">{{ __('Default Client') }}</label>

                <input type="hidden" name="daftra_default_client_id" :value="selectedId">

                <template x-if="selectedId !== ''">
                    <div class="flex items-center gap-3 w-full rounded-lg border border-line bg-surface-input text-ink px-4 py-2.5">
                        <template x-if="selectedAvatar">
                            <img :src="selectedAvatar" class="w-8 h-8 rounded-full object-cover">
                        </template>
                        <template x-if="!selectedAvatar">
                            <div class="w-8 h-8 rounded-full bg-surface-2 flex items-center justify-center">
                                <svg class="w-4 h-4 text-ink-muted" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 19.5a8.25 8.25 0 0115 0" />
                                </svg>
                            </div>
                        </template>
                        <span class="flex-1 text-sm text-ink" x-text="selectedName"></span>
                        <button type="button" @click="selectedId = ''; selectedName = ''; selectedAvatar = ''" class="text-ink-muted hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </template>

                <template x-if="selectedId === ''">
                    <div class="relative">
                        <input
                            type="text"
                            x-model="query"
                            @input.debounce.300ms="search()"
                            @focus="query.length >= 2 && (open = true)"
                            @click.away="open = false"
                            placeholder="{{ __('Search for a client…') }}"
                            class="w-full rounded-lg border border-line bg-surface-input text-ink px-4 py-2.5 text-sm focus:ring-2 focus:ring-accent-ring focus:border-accent-ring outline-none transition"
                        >
                        <template x-if="loading">
                            <div class="absolute right-3 top-1/2 -translate-y-1/2">
                                <svg class="w-4 h-4 animate-spin text-[#706f6c]" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </div>
                        </template>
                        <template x-if="!loading && query.length < 2 && query.length > 0">
                            <p class="mt-1 text-xs text-ink-muted" x-text="minCharsText"></p>
                        </template>

                        <div
                            x-show="open && !loading"
                            @click.away="open = false"
                            class="absolute z-50 w-full mt-1 bg-surface-1 border border-line rounded-lg shadow-lg max-h-60 overflow-y-auto"
                            style="display: none;"
                        >
                            <template x-if="error">
                                <div class="px-4 py-2.5 text-sm text-tone-danger" x-text="searchFailedText"></div>
                            </template>
                            <template x-if="!error && results.length === 0">
                                <div class="px-4 py-2.5 text-sm text-ink-muted" x-text="noResultsText"></div>
                            </template>
                            <template x-for="client in results" :key="client.id">
                                <div
                                    @click="selectedId = client.id; selectedName = client.name; selectedAvatar = client.avatar || ''; open = false"
                                    class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-surface-2"
                                >
                                    <template x-if="client.avatar">
                                        <img :src="client.avatar" class="w-6 h-6 rounded-full object-cover">
                                    </template>
                                    <template x-if="!client.avatar">
                                        <div class="w-6 h-6 rounded-full bg-surface-2 flex items-center justify-center">
                                            <svg class="w-3 h-3 text-ink-muted" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 19.5a8.25 8.25 0 0115 0" />
                                            </svg>
                                        </div>
                                    </template>
                                    <span class="text-sm text-ink" x-text="client.name"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <p class="mt-1 text-xs text-ink-muted">
                    {{ __('Client used when a Foodics order has no customer (walk-in).') }}
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-accent text-on-accent hover:bg-accent-hover transition-all duration-200 cursor-pointer btn-shadow disabled:opacity-60 disabled:cursor-not-allowed">
                    <svg x-show="!saving" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <svg x-show="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" style="display:none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    <span x-text="saving ? '{{ __('Saving…') }}' : '{{ __('Save Settings') }}'">{{ __('Save Settings') }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
@endsection