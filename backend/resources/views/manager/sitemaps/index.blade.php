@extends('layouts.app')

@section('title', 'サイトマップ')

@section('content')
    @include('manager.partials.header')
    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-medium">サイトマップ</h1>

        <form class="mt-10" method="POST" action="{{ route('manager.sitemaps.store') }}">
            @csrf
            <div>
                <label class="block text-sm" for="url">sitemap.xml の URL</label>
                <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <input
                            id="url"
                            class="w-full rounded-sm border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                            type="url"
                            name="url"
                            value="{{ old('url') }}"
                            required
                            placeholder="https://example.com/sitemap.xml"
                        >
                        @error('url')
                            <p class="mt-1 text-sm text-[#f53003]" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                    <button class="shrink-0 self-start rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white" type="submit">登録</button>
                </div>
            </div>
        </form>

        @if ($sitemaps->isEmpty())
            <p class="mt-8 text-sm">登録された sitemap はありません。</p>
        @else
            <div class="mt-8 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm" data-csrf="{{ csrf_token() }}">
                    <thead>
                        <tr class="border-b border-[#e3e3e0]">
                            <th class="px-3 py-2 font-medium">URL</th>
                            <th class="px-3 py-2 font-medium">状態</th>
                            <th class="px-3 py-2 font-medium">掲載数</th>
                            <th class="px-3 py-2 font-medium whitespace-nowrap">
                                <span class="inline-flex items-center gap-1">
                                    削除までの回数
                                    <button
                                        class="help-mark"
                                        type="button"
                                        popovertarget="absence-limit-help"
                                        aria-expanded="false"
                                        aria-label="削除までの回数の説明"
                                    >
                                        <span aria-hidden="true">?</span>
                                    </button>
                                </span>
                            </th>
                            <th class="px-3 py-2 font-medium whitespace-nowrap">巡回日時</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sitemaps as $sitemap)
                            <tr class="border-b border-[#e3e3e0] align-top">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
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
                                </td>
                                <td data-sitemap-status @class(['px-3 py-2', 'text-[#f53003]' => filled($sitemap->error_message)])>{{ filled($sitemap->error_message) ? $sitemap->error_message : $sitemap->status->label() }}</td>
                                <td class="px-3 py-2" data-sitemap-pages data-pages-url="{{ route('manager.sitemaps.show', $sitemap) }}">
                                    @if ($sitemap->pages_count > 0)
                                        <a class="underline underline-offset-4" href="{{ route('manager.sitemaps.show', $sitemap) }}">{{ $sitemap->pages_count }}</a>
                                    @else
                                        {{ $sitemap->pages_count }}
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div
                                        class="inline-flex items-center gap-1"
                                        data-absence
                                        data-absence-limit="{{ $sitemap->absence_limit ?? '' }}"
                                        data-url="{{ route('manager.sitemaps.absence-limit', $sitemap) }}"
                                    >
                                        <span data-absence-label>{{ $sitemap->absenceLimitLabel() }}</span>
                                        <select
                                            class="rounded-sm border border-[#e3e3e0] bg-white px-2 py-1 text-sm"
                                            data-absence-select
                                            aria-label="削除までの回数"
                                            hidden
                                        >
                                            @for ($count = 1; $count <= 5; $count++)
                                                <option value="{{ $count }}" @selected($sitemap->absence_limit === $count)>{{ $count }}回</option>
                                            @endfor
                                            <option value="" @selected($sitemap->absence_limit === null)>削除しない</option>
                                        </select>
                                        <button
                                            class="inline-flex items-center justify-center rounded-sm p-1"
                                            type="button"
                                            data-absence-edit
                                            aria-label="削除までの回数を変更"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path d="M13.586 3.586a2 2 0 0 1 2.828 2.828l-8.5 8.5a1 1 0 0 1-.414.26l-3.2.8a.5.5 0 0 1-.606-.606l.8-3.2a1 1 0 0 1 .26-.414l8.5-8.5z" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap" data-sitemap-crawled-at>{{ $sitemap->crawled_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-3 whitespace-nowrap">
                                        <button
                                            class="rounded-sm bg-[#1b1b18] px-3 py-2 text-sm text-white"
                                            type="button"
                                            data-sitemap-crawl
                                            data-url="{{ route('manager.sitemaps.crawl', $sitemap) }}"
                                        >今すぐ巡回</button>
                                        <form method="POST" action="{{ route('manager.sitemaps.destroy', $sitemap) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="rounded-sm bg-[#f53003] px-3 py-2 text-sm text-white"
                                                type="button"
                                                data-sitemap-delete
                                                data-sitemap-url="{{ $sitemap->url }}"
                                            >削除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div id="absence-limit-help" class="help-popover" data-help-popover popover>
                <p>管理画面に残っている URL がサイトマップから外れたとき、サイトマップを読み直して何回続けて見つからなければ、その URL を管理画面から削除するかの設定です。途中で再び載ると、回数は最初からになります。「削除しない」にすると削除しません。</p>
            </div>
            <dialog
                class="sitemap-delete-dialog"
                data-sitemap-delete-dialog
                aria-labelledby="sitemap-delete-title"
                aria-describedby="sitemap-delete-description"
            >
                <h2 id="sitemap-delete-title" class="text-lg font-medium">このサイトマップを削除してよろしいですか？</h2>
                <p id="sitemap-delete-description" class="mt-4 break-all text-sm" data-sitemap-delete-url></p>
                <p class="mt-2 text-sm">掲載されている URL も削除されます。</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button class="rounded-sm border border-[#e3e3e0] bg-white px-4 py-2 text-sm" type="button" data-sitemap-delete-cancel>キャンセル</button>
                    <button class="rounded-sm bg-[#f53003] px-4 py-2 text-sm text-white" type="button" data-sitemap-delete-confirm>削除する</button>
                </div>
            </dialog>
        @endif
    </main>
@endsection
