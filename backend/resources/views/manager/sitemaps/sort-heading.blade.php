<span class="inline-flex items-center gap-1.5 whitespace-nowrap">
    <span>{{ $label }}</span>
    <span class="inline-flex items-center gap-0.5">
        @foreach (['asc' => '昇順', 'desc' => '降順'] as $direction => $directionLabel)
            <a
                href="{{ route('manager.sitemaps.show', ['sitemap' => $sitemap, ...$filters, 'sort' => $column, 'order' => $direction]) }}"
                @class([
                    'inline-flex h-5 w-5 items-center justify-center rounded-sm border',
                    'border-[#1b1b18] bg-[#1b1b18] text-white' => $sort === $column && $order === $direction,
                    'border-[#e3e3e0] bg-white text-[#1b1b18] hover:border-[#1b1b18]' => ! ($sort === $column && $order === $direction),
                ])
                aria-label="{{ $label }}の{{ $directionLabel }}"
                @if ($sort === $column && $order === $direction) aria-current="true" @endif
            >
                <svg @class(['h-2.5 w-2.5', 'rotate-180' => $direction === 'desc']) viewBox="0 0 10 10" fill="currentColor" aria-hidden="true">
                    <path d="M5 1.5 9 8.5H1L5 1.5Z" />
                </svg>
            </a>
        @endforeach
    </span>
</span>
