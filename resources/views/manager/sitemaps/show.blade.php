@extends('layouts.app')

@section('title', '掲載URL')

@section('content')
    @include('manager.partials.header')
    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-medium">掲載URL</h1>
        <div class="mt-4 flex items-center gap-2 text-sm">
            <span class="min-w-0 break-all">{{ $sitemap->url }}</span>
            <a
                class="inline-flex shrink-0 items-center justify-center rounded-sm p-1"
                href="{{ $sitemap->url }}"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="別ウィンドウで開く"
                title="別ウィンドウで開く"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Z" clip-rule="evenodd" />
                    <path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 0 0 1.06.053L16.5 4.44v2.81a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.553l-9.056 8.194a.75.75 0 0 0-.053 1.06Z" clip-rule="evenodd" />
                </svg>
            </a>
        </div>
        <p class="mt-1 text-sm">{{ $sitemap->status->label() }}</p>

        <form class="mt-8" method="GET" action="{{ route('manager.sitemaps.show', $sitemap) }}">
            @if ($sort !== '')
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="order" value="{{ $order }}">
            @endif
            <div class="flex items-end gap-2">
                <div class="min-w-0 grow">
                    <label class="block text-sm" for="q">URL</label>
                    <input
                        id="q"
                        class="mt-1 w-full rounded-sm border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                        type="search"
                        name="q"
                        value="{{ $query }}"
                        placeholder="URLを検索"
                    >
                </div>
                <div>
                    <label class="block text-sm" for="searchable">検索対象</label>
                    <select
                        id="searchable"
                        class="mt-1 rounded-sm border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                        name="searchable"
                    >
                        <option value="" @selected($searchable === '')>すべて</option>
                        <option value="1" @selected($searchable === '1')>ON</option>
                        <option value="0" @selected($searchable === '0')>OFF</option>
                    </select>
                </div>
                <button class="shrink-0 rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white" type="submit">検索</button>
            </div>
        </form>

        @if ($pages->isEmpty())
            <p class="mt-8 text-sm">{{ $query === '' && $searchable === '' ? '掲載されている URL はありません。' : '一致する URL はありません。' }}</p>
        @else
            <div class="mt-8 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm" data-csrf="{{ csrf_token() }}">
                    <thead>
                        <tr class="border-b border-[#e3e3e0]">
                            <th class="px-3 py-2 font-medium">検索対象</th>
                            <th class="px-3 py-2 font-medium">
                                @include('manager.sitemaps.sort-heading', ['label' => 'URL', 'column' => 'url'])
                            </th>
                            <th class="px-3 py-2 font-medium">ステータス</th>
                            <th class="px-3 py-2 font-medium">
                                @include('manager.sitemaps.sort-heading', ['label' => '容量', 'column' => 'size'])
                            </th>
                            <th class="px-3 py-2 font-medium">
                                @include('manager.sitemaps.sort-heading', ['label' => '巡回日時', 'column' => 'crawled_at'])
                            </th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr @class(['border-b border-[#e3e3e0] align-top']) @unless ($page->searchable) data-search-off="true" @endunless>
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        class="search-toggle"
                                        role="switch"
                                        aria-checked="{{ $page->searchable ? 'true' : 'false' }}"
                                        aria-label="検索対象"
                                        data-search-toggle
                                        data-url="{{ route('manager.sitemaps.pages.searchable', [$sitemap, $page]) }}"
                                    >
                                        <span class="search-toggle-track" aria-hidden="true">
                                            <span class="search-toggle-knob"></span>
                                        </span>
                                    </button>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="min-w-0 break-all">{{ $page->url }}</span>
                                        <a
                                            class="inline-flex shrink-0 items-center justify-center rounded-sm p-1"
                                            href="{{ $page->url }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label="別ウィンドウで開く"
                                            title="別ウィンドウで開く"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Z" clip-rule="evenodd" />
                                                <path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 0 0 1.06.053L16.5 4.44v2.81a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.553l-9.056 8.194a.75.75 0 0 0-.053 1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </a>
                                    </div>
                                    @if ($page->unlisted_at !== null)
                                        <p class="mt-1 text-[#f53003]">サイトマップから外れた</p>
                                    @endif
                                </td>
                                <td @class(['px-3 py-2', 'text-[#f53003]' => filled($page->error_message)]) data-crawl-status>{{ filled($page->error_message) ? $page->error_message : $page->http_status }}</td>
                                <td class="px-3 py-2" data-crawl-size>{{ $page->formattedResponseSize() }}</td>
                                <td class="px-3 py-2" data-crawl-time>{{ $page->crawled_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        class="whitespace-nowrap rounded-sm bg-[#1b1b18] px-3 py-2 text-sm text-white"
                                        data-crawl
                                        data-url="{{ route('manager.sitemaps.pages.crawl', [$sitemap, $page]) }}"
                                    >今すぐ巡回</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $pages->links() }}</div>
        @endif
    </main>
@endsection