<header class="border-b border-[#e3e3e0]">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-4">
        <nav class="flex items-center gap-4 text-sm" aria-label="グローバルナビゲーション">
            <a
                class="underline underline-offset-4"
                href="{{ route('sitemap.index') }}"
                @if (request()->routeIs('sitemap.index')) aria-current="page" @endif
            >サイトマップ</a>
        </nav>
        <div class="ml-auto flex items-center gap-4">
            @include('partials.search-box', ['id' => 'header-search'])
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-sm underline underline-offset-4" type="submit">ログアウト</button>
            </form>
        </div>
    </div>
</header>
