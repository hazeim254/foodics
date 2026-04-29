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

        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6">
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

            <div class="mb-4">
                <label for="daftra_default_client_id" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Default Client ID') }}</label>

                <input
                    type="text"
                    id="daftra_default_client_id"
                    name="daftra_default_client_id"
                    value="{{ old('daftra_default_client_id', $daftraDefaultClientId) }}"
                    class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                    placeholder="e.g. 12345"
                />

                @error('daftra_default_client_id')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ __('Client used when a Foodics order has no customer (walk-in).') }}
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
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
