@extends('layouts.app')

@section('title', __('Contact Us'))

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-semibold mb-6">{{ __('Contact Us') }}</h1>

    @if(session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('contact.store') }}">
        @csrf

        <div class="bg-white dark:bg-[#161615] rounded-lg shadow-sm border border-[#e3e3e0] dark:border-[#3E3E3A] p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Name') }}</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', auth()->user()->name) }}"
                        class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                        required
                    />
                    @error('name')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Email') }}</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{{ old('email', auth()->user()->email) }}"
                        class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                        required
                    />
                    @error('email')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="phone" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Phone (optional)') }}</label>
                    <input
                        type="text"
                        id="phone"
                        name="phone"
                        value="{{ old('phone') }}"
                        class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                        placeholder="+966 5x xxx xxxx"
                    />
                    @error('phone')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Type') }}</label>
                    <select
                        id="type"
                        name="type"
                        class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                        required
                    >
                        <option value="inquiry" {{ old('type') === 'inquiry' ? 'selected' : '' }}>{{ __('Inquiry') }}</option>
                        <option value="suggestion" {{ old('type') === 'suggestion' ? 'selected' : '' }}>{{ __('Suggestion') }}</option>
                        <option value="complaint" {{ old('type') === 'complaint' ? 'selected' : '' }}>{{ __('Complaint') }}</option>
                    </select>
                    @error('type')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label for="subject" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Subject') }}</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    value="{{ old('subject') }}"
                    class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition"
                    required
                />
                @error('subject')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="message" class="block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC] mb-1">{{ __('Message') }}</label>
                <textarea
                    id="message"
                    name="message"
                    rows="5"
                    class="w-full rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#1b1b18] text-[#1b1b18] dark:text-[#EDEDEC] px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#4A90D9] focus:border-[#4A90D9] outline-none transition resize-y"
                    required
                >{{ old('message') }}</textarea>
                @error('message')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-[#4A90D9] text-white hover:bg-[#3A7BC8] transition-colors cursor-pointer">
                    <svg class="w-4 h-4 rtl:scale-x-[-1]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                    </svg>
                    {{ __('Send Message') }}
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
