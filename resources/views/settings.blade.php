@extends('layouts.app')

@section('title', __('Settings'))

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-semibold mb-6">{{ __('Settings') }}</h1>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf

        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6 card-accent">
            <h2 class="text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-4">{{ __('Daftra Integration') }}</h2>

            @if($branches !== null)
                <div class="mb-4">
                    <label for="daftra_default_branch_id" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Default Branch') }}</label>

                    <select
                        id="daftra_default_branch_id"
                        name="daftra_default_branch_id"
                        class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                    >
                        @foreach($branches as $branch)
                            <option value="{{ $branch['id'] }}" {{ old('daftra_default_branch_id', $daftraDefaultBranchId ?? '') == $branch['id'] ? 'selected' : '' }}>
                                {{ $branch['name'] }}
                            </option>
                        @endforeach
                    </select>

                    @error('daftra_default_branch_id')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {{ __('Daftra branch used for all API requests.') }}
                    </p>
                </div>
            @endif

            <div
                x-data="{
                    selectedId: '{{ $daftraDefaultClientId ?? '' }}',
                    selectedName: '{{ $daftraDefaultClient['name'] ?? '' }}',
                    selectedAvatar: '{{ $daftraDefaultClient['avatar'] ?? '' }}',
                    query: '',
                    results: [],
                    loading: false,
                    open: false,
                    debounceTimer: null,
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
                                this.results = data.data;
                                this.open = true;
                            })
                            .catch(() => {
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
                <label class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Default Client') }}</label>

                <input type="hidden" name="daftra_default_client_id" :value="selectedId">

                <template x-if="selectedId !== ''">
                    <div class="flex items-center gap-3 w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] px-4 py-2.5">
                        <template x-if="selectedAvatar">
                            <img :src="selectedAvatar" class="w-8 h-8 rounded-full object-cover">
                        </template>
                        <template x-if="!selectedAvatar">
                            <div class="w-8 h-8 rounded-full bg-[#F5F5F3] dark:bg-[#262625] flex items-center justify-center">
                                <svg class="w-4 h-4 text-[#706f6c] dark:text-[#A1A09A]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 19.5a8.25 8.25 0 0115 0" />
                                </svg>
                            </div>
                        </template>
                        <span class="flex-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]" x-text="selectedName"></span>
                        <button type="button" @click="selectedId = ''; selectedName = ''; selectedAvatar = ''" class="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">
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
                            placeholder="Search for a client…"
                            class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
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
                            <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">Type at least 2 characters to search…</p>
                        </template>

                        <div
                            x-show="open && !loading"
                            @click.away="open = false"
                            class="absolute z-50 w-full mt-1 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg shadow-lg max-h-60 overflow-y-auto"
                            style="display: none;"
                        >
                            <template x-if="results.length === 0">
                                <div class="px-4 py-2.5 text-sm text-[#706f6c] dark:text-[#A1A09A]">No clients found.</div>
                            </template>
                            <template x-for="client in results" :key="client.id">
                                <div
                                    @click="selectedId = client.id; selectedName = client.name; selectedAvatar = client.avatar || ''; open = false"
                                    class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-[#F5F5F3] dark:hover:bg-[#262625]"
                                >
                                    <template x-if="client.avatar">
                                        <img :src="client.avatar" class="w-6 h-6 rounded-full object-cover">
                                    </template>
                                    <template x-if="!client.avatar">
                                        <div class="w-6 h-6 rounded-full bg-[#F5F5F3] dark:bg-[#262625] flex items-center justify-center">
                                            <svg class="w-3 h-3 text-[#706f6c] dark:text-[#A1A09A]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 19.5a8.25 8.25 0 0115 0" />
                                            </svg>
                                        </div>
                                    </template>
                                    <span class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]" x-text="client.name"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ __('Client used when a Foodics order has no customer (walk-in).') }}
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-all duration-200 cursor-pointer btn-shadow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    {{ __('Save Settings') }}
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
