@extends('layouts.app')

@section('title', '検索')

@section('content')
    @include('manager.partials.header')
    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-medium">{{ $query !== '' ? '「'.$query.'」の検索結果' : '検索' }}</h1>
        <div class="mt-6">
            @include('partials.search-box', ['id' => 'content-search'])
        </div>

        @if ($error !== null)
            <p class="mt-8 text-sm text-[#f53003]" role="alert">{{ $error }}</p>
        @elseif ($query !== '' && $results === [])
            <p class="mt-8 text-sm">一致する URL はありません。</p>
        @elseif ($results !== [])
            <div class="mt-8 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-[#e3e3e0]">
                            <th class="px-3 py-2 font-medium">URL</th>
                            <th class="px-3 py-2 font-medium">関連度</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $result)
                            <tr class="border-b border-[#e3e3e0] align-top">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="min-w-0 break-all">{{ $result['page']->url }}</span>
                                        <a
                                            class="inline-flex shrink-0 items-center justify-center rounded-sm p-1"
                                            href="{{ $result['page']->url }}"
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
                                    @if ($result['page']->unlisted_at !== null)
                                        <p class="mt-1 text-[#f53003]">サイトマップから外れた</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ number_format($result['relevance'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
@endsection
