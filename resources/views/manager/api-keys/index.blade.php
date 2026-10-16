@extends('layouts.app')

@section('title', 'APIキー')

@section('content')
    @include('manager.partials.header')
    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-medium">APIキー</h1>

        <form class="mt-10" method="POST" action="{{ route('manager.api-keys.store') }}">
            @csrf
            <button class="rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white" type="submit">発行</button>
        </form>

        @if ($apiKeys->isEmpty())
            <p class="mt-8 text-sm">発行された APIキーはありません。</p>
        @else
            <div class="mt-8 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-[#e3e3e0]">
                            <th class="px-3 py-2 font-medium">キー</th>
                            <th class="px-3 py-2 font-medium whitespace-nowrap">最終利用日</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($apiKeys as $apiKey)
                            <tr class="border-b border-[#e3e3e0] align-top">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="min-w-0 break-all">{{ $apiKey->token }}</span>
                                        <button
                                            class="inline-flex shrink-0 items-center justify-center rounded-sm p-1"
                                            type="button"
                                            data-api-key-copy
                                            data-api-key-token="{{ $apiKey->token }}"
                                            aria-label="コピー"
                                            title="コピー"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                <path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z" />
                                                <path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $apiKey->last_used_at?->format('Y-m-d') ?? '未使用' }}</td>
                                <td class="px-3 py-2">
                                    <form method="POST" action="{{ route('manager.api-keys.destroy', $apiKey) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            class="rounded-sm bg-[#f53003] px-3 py-2 text-sm text-white"
                                            type="button"
                                            data-api-key-delete
                                            data-api-key-token="{{ $apiKey->token }}"
                                        >無効化</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <dialog
                class="api-key-delete-dialog"
                data-api-key-delete-dialog
                aria-labelledby="api-key-delete-title"
                aria-describedby="api-key-delete-description"
            >
                <h2 id="api-key-delete-title" class="text-lg font-medium">この APIキーを無効化してよろしいですか？</h2>
                <p id="api-key-delete-description" class="mt-4 break-all text-sm" data-api-key-delete-token></p>
                <p class="mt-2 text-sm">無効化すると元に戻せません。</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button class="rounded-sm border border-[#e3e3e0] bg-white px-4 py-2 text-sm" type="button" data-api-key-delete-cancel>キャンセル</button>
                    <button class="rounded-sm bg-[#f53003] px-4 py-2 text-sm text-white" type="button" data-api-key-delete-confirm>無効化する</button>
                </div>
            </dialog>
        @endif
    </main>
@endsection
