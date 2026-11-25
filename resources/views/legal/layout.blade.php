<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $appName }}</title>
    <meta name="description" content="@yield('description')">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 font-sans text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
            <a href="{{ url('/') }}" class="font-semibold">{{ $appName }}</a>
            <nav aria-label="Policies" class="flex gap-4 text-sm">
                <a href="{{ route('legal.privacy') }}" class="text-indigo-700 hover:underline">Privacy</a>
                <a href="{{ route('legal.terms') }}" class="text-indigo-700 hover:underline">Terms</a>
                <a href="{{ route('legal.data-deletion') }}" class="text-indigo-700 hover:underline">Data deletion</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8">
        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <h1 class="text-2xl font-semibold">@yield('title')</h1>
            <p class="mt-1 text-sm text-slate-500">Last updated {{ $effectiveDate }}</p>
            <div class="legal-prose mt-6">
                @yield('content')
            </div>
        </article>
    </main>

    @include('legal.footer')
</body>
</html>
