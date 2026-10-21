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
            ['route' => 'items.index', 'pattern' => ['items.index', 'items.show'], 'label' => 'Unread',
                'count' => \App\Models\Item::query()->unread()->count(),
                'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25'],
            ['route' => 'items.archive', 'pattern' => ['items.archive'], 'label' => 'Archive',
                'icon' => 'm20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z'],
            ['route' => 'items.favourites', 'pattern' => ['items.favourites'], 'label' => 'Favourites',
                'icon' => 'M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z'],
            ['route' => 'tags.index', 'pattern' => ['tags.*', 'items.tagged'], 'label' => 'Tags',
                'icon' => 'M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z'],
            ['route' => 'import.index', 'pattern' => ['import.*'], 'label' => 'Import & export',
                'icon' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3'],
            ['route' => 'settings.edit', 'pattern' => ['settings.*'], 'label' => 'Settings',
                'icon' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75'],
            ['route' => 'tools.index', 'pattern' => ['tools.*'], 'label' => 'Tools',
                'icon' => 'M21.75 6.75a4.5 4.5 0 0 1-4.5 4.5H11.31l-4.72 4.72a.75.75 0 0 1-1.06 0l-1.06-1.06a.75.75 0 0 1 0-1.06l4.72-4.72V6.75a4.5 4.5 0 0 1 4.5-4.5h1.5a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 0 .75.75h1.5a.75.75 0 0 1 .75.75v1.5Z'],
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
                    <x-sidebar-link :href="route($item['route'])" :active="request()->routeIs(...$item['pattern'])">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                        </x-slot>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if (! empty($item['count']))
                            <span class="rounded-full bg-slate-800 px-2 py-0.5 text-xs font-semibold text-slate-300">{{ $item['count'] }}</span>
                        @endif
                    </x-sidebar-link>
                @endforeach
            </nav>

            <div class="border-t border-slate-800 p-3">
                <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-700 text-sm font-semibold text-white">
                        {{ mb_substr(Auth::user()->name, 0, 1) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-white">{{ Auth::user()->name }}</p>
                        <div class="flex gap-3 text-xs text-slate-400">
                            <a href="{{ route('profile.edit') }}" class="hover:text-white">Profile</a>
                            <form method="POST" action="{{ route('logout') }}" data-clears-offline>
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
            <a href="{{ route('items.index') }}" class="flex items-center gap-2.5">
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
                    <x-sidebar-link :href="route($item['route'])" :active="request()->routeIs(...$item['pattern'])">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                        </x-slot>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if (! empty($item['count']))
                            <span class="rounded-full bg-slate-800 px-2 py-0.5 text-xs font-semibold text-slate-300">{{ $item['count'] }}</span>
                        @endif
                    </x-sidebar-link>
                @endforeach
            </nav>
            <div class="mt-3 flex items-center justify-between border-t border-slate-800 px-3 pt-3 text-sm">
                <a href="{{ route('profile.edit') }}"
                    class="text-slate-300 hover:text-white">{{ Auth::user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}" data-clears-offline>
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