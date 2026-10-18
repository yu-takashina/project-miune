@extends('layouts.app')

@section('title', 'ログイン')

@section('content')
    <main class="flex min-h-screen items-center justify-center px-6 py-12">
        <div class="w-full max-w-md rounded-lg border border-[#e3e3e0] bg-white p-8 shadow-sm">
            <h1 class="text-xl font-medium">ログイン</h1>

            @if ($errors->any())
                <ul class="mt-6 space-y-1 text-sm text-[#f53003]" role="alert">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <form class="mt-6 space-y-4" method="POST" action="{{ route('login') }}">
                @csrf
                <div>
                    <label class="block text-sm" for="email">メールアドレス</label>
                    <input
                        id="email"
                        class="mt-1 w-full rounded-sm border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                    >
                </div>
                <div>
                    <label class="block text-sm" for="password">パスワード</label>
                    <input
                        id="password"
                        class="mt-1 w-full rounded-sm border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >
                </div>
                <button
                    class="w-full rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white"
                    type="submit"
                >
                    ログイン
                </button>
            </form>
        </div>
    </main>
@endsection
