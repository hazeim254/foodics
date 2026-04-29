@props(['type' => 'success'])

@php
$componentClass = new \App\View\Components\Alert($type);
@endphp

<div {{ $attributes->merge(['class' => 'mb-4 p-3 rounded-lg border flex items-center gap-3 text-sm ' . $componentClass->containerClasses()]) }}>
    <svg class="w-5 h-5 shrink-0 {{ $componentClass->iconClasses() }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $componentClass->iconPath() }}" />
    </svg>
    <span>{{ $slot }}</span>
</div>