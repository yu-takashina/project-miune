<form method="GET" action="{{ route('search') }}" role="search">
    <label class="sr-only" for="{{ $id }}">検索</label>
    <div class="relative">
        <svg class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-[#706f6c]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
        </svg>
        <input
            id="{{ $id }}"
            class="w-40 rounded-sm border border-[#e3e3e0] bg-white py-1.5 pr-3 pl-8 text-sm sm:w-56"
            type="search"
            name="q"
            value="{{ request()->routeIs('search') ? request()->string('q') : '' }}"
            placeholder="検索"
        >
    </div>
</form>
