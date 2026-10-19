<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Docket') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @include('partials.pwa')

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans text-slate-900 antialiased">
    <div class="flex min-h-screen flex-col items-center bg-slate-100 pt-6 sm:justify-center sm:pt-0">
        <a href="/" class="flex flex-col items-center gap-3">
            <x-application-logo class="h-14 w-14" />
            <span class="text-2xl font-bold tracking-tight text-slate-900">Docket</span>
        </a>

        <div class="mt-8 w-full bg-white px-8 py-8 shadow-sm border border-slate-200 sm:max-w-md sm:rounded-2xl">
            @if (session('status'))
                <x-alert />
            @endif

            {{ $slot }}
        </div>

        <p class="mt-6 text-xs text-slate-400">Docket — cost &amp; expense tracker</p>
    </div>
</body>

</html>