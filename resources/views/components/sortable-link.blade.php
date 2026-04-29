<a href="{{ $url }}" class="flex items-center gap-1 hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">
    {{ __($label) }}
    @if($isActive)
        <svg class="w-3 h-3 {{ request('sort_dir', 'desc') === 'asc' ? '' : 'rotate-180' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
        </svg>
    @endif
</a>
