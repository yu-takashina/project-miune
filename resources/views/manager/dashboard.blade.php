@extends('layouts.app')

@section('title', 'ダッシュボード')

@section('content')
    <header class="border-b border-[#e3e3e0]">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <p class="text-sm">{{ $user->name }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-sm underline underline-offset-4" type="submit">ログアウト</button>
            </form>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-medium">ダッシュボード</h1>
    </main>
@endsection
