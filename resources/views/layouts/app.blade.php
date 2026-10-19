<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

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

<body class="h-full font-sans antialiased bg-slate-100 text-slate-900">
    @php
        $navigation = [
            ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Dashboard',
                'icon' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
            ['route' => 'expenses.index', 'pattern' => 'expenses.*', 'label' => 'Expenses',
                'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z'],
            ['route' => 'income.index', 'pattern' => 'income.*', 'label' => 'Income',
                'icon' => 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941'],
            ['route' => 'cashflows.index', 'pattern' => 'cashflows.*', 'label' => 'Cash flows',
                'icon' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5'],
            ['route' => 'capture.create', 'pattern' => 'capture.*', 'label' => 'Capture',
                'icon' => 'M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z'],
            ['route' => 'reports.index', 'pattern' => 'reports.*', 'label' => 'Reports',
                'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
            ['route' => 'balances.index', 'pattern' => 'balances.*', 'label' => 'Balances',
                'icon' => 'M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3'],
            ['route' => 'ledger.index', 'pattern' => 'ledger.*', 'label' => 'Ledger',
                'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25'],
            ['route' => 'settings.edit', 'pattern' => 'settings.*', 'label' => 'Settings',
                'icon' => 'M10.34 3.94c.09-.34.27-.64.51-.88l2.47-2.47a1.125 1.125 0 0 1 1.59 1.59l-2.47 2.47c-.24.24-.54.42-.88.51l-1.71.51a.375.375 0 0 1-.47-.47l.51-1.71Zm.53 3.99a.75.75 0 0 0-1.06 0l-4.5 4.5a.75.75 0 0 0 0 1.06l2.25 2.25a.75.75 0 0 0 1.06 0l4.5-4.5a.75.75 0 0 0 0-1.06l-2.25-2.25ZM9.75 21h9a.75.75 0 0 0 .75-.75v-9a.75.75 0 0 0-.75-.75h-9a.75.75 0 0 0-.75.75v9c0 .414.336.75.75.75Z'],
            ['route' => 'tools.index', 'pattern' => 'tools.*', 'label' => 'Tools',
                'icon' => 'M21.75 6.75a4.5 4.5 0 0 1-4.5 4.5H11.31l-4.72 4.72a.75.75 0 0 1-1.06 0l-1.06-1.06a.75.75 0 0 1 0-1.06l4.72-4.72V6.75a4.5 4.5 0 0 1 4.5-4.5h1.5a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 0 .75.75h1.5a.75.75 0 0 1 .75.75v1.5Z'],
            ['route' => 'lookups.index', 'pattern' => 'lookups.*', 'label' => 'Lookups',
                'icon' => 'M8.25 6.75h7.5M8.25 12h7.5M8.25 17.25h7.5M3.75 6.75h.008v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.008v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.008v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z'],
            ['route' => 'import.index', 'pattern' => 'import.*', 'label' => 'Import',
                'icon' => 'M12 9.75v6.75m0 0-3-3m3 3 3-3m-8.25 6a4.5 4.5 0 0 1-1.41-8.775 4.5 4.5 0 0 1 8.774 0 4.5 4.5 0 0 1-1.41 8.775c-.184.097-.38.175-.585.233M9 15.75h6m-6 0v-1.5m6 0v-1.5'],
        ];
    @endphp

    <div x-data="{ mobileMenu: false }" class="min-h-full">

        {{-- Desktop sidebar --}}
        <aside class="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-60 lg:flex-col bg-slate-900">
            <div class="flex h-16 shrink-0 items-center gap-3 px-5">
                <x-application-logo class="h-8 w-8" />
                <span class="text-lg font-bold tracking-tight text-white">Docket</span>
            </div>

            <nav class="flex flex-1 flex-col gap-1 px-3 py-4">
                @foreach ($navigation as $item)
                    <x-sidebar-link :href="route($item['route'])" :active="request()->routeIs($item['pattern'])">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                        </x-slot>
                        {{ $item['label'] }}
                    </x-sidebar-link>
                @endforeach
            </nav>

            <div class="border-t border-slate-800 p-3">
                <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white">
                        {{ mb_substr(Auth::user()->name, 0, 1) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-white">{{ Auth::user()->name }}</p>
                        <div class="flex gap-3 text-xs text-slate-400">
                            <a href="{{ route('profile.edit') }}" class="hover:text-white">Profile</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="hover:text-white">Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        {{-- Mobile top bar --}}
        <header class="sticky top-0 z-40 flex h-14 items-center justify-between bg-slate-900 px-4 lg:hidden">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <x-application-logo class="h-7 w-7" />
                <span class="text-base font-bold tracking-tight text-white">Docket</span>
            </a>
            <button @click="mobileMenu = !mobileMenu"
                class="rounded-md p-2 text-slate-300 hover:bg-slate-800 hover:text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path x-show="!mobileMenu" stroke-linecap="round" stroke-linejoin="round"
                        d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    <path x-show="mobileMenu" x-cloak stroke-linecap="round" stroke-linejoin="round"
                        d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        {{-- Mobile menu panel --}}
        <div x-show="mobileMenu" x-cloak @click.away="mobileMenu = false"
            class="fixed inset-x-0 top-14 z-30 border-b border-slate-800 bg-slate-900 px-3 pb-4 pt-2 lg:hidden">
            <nav class="flex flex-col gap-1">
                @foreach ($navigation as $item)
                    <x-sidebar-link :href="route($item['route'])" :active="request()->routeIs($item['pattern'])">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                        </x-slot>
                        {{ $item['label'] }}
                    </x-sidebar-link>
                @endforeach
            </nav>
            <div class="mt-3 flex items-center justify-between border-t border-slate-800 px-3 pt-3 text-sm">
                <a href="{{ route('profile.edit') }}"
                    class="text-slate-300 hover:text-white">{{ Auth::user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-400 hover:text-white">Log out</button>
                </form>
            </div>
        </div>

        {{-- Main column --}}
        <div class="lg:pl-60">
            @isset($header)
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main>
                @if (session('status') || session('error'))
                    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-6 space-y-4">
                        @if (session('status'))
                            <x-alert />
                        @endif
                        @if (session('error'))
                            <x-alert type="error" :message="session('error')" />
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>

</html>