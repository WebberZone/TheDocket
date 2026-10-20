@php
    /** @var \App\Models\Item $item */
    $failed = $item->fetch_status === \App\Enums\FetchStatus::Failed;
    $pending = $item->fetch_status === \App\Enums\FetchStatus::Pending;
@endphp
<!DOCTYPE html>
<html lang="{{ $item->language ?: str_replace('_', '-', app()->getLocale()) }}" class="reader theme-light font-family-serif">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">

    <title>{{ $item->displayTitle() }} — {{ config('app.name', 'Docket') }}</title>

    {{-- Appearance before first paint, so a dark-theme reader never flashes white. Mirrors resources/js/reader.js. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var p = { theme: 'light', family: 'serif', size: 20 };
            try { Object.assign(p, JSON.parse(localStorage.getItem('docket.reader') || '{}')); } catch (e) {}
            var r = document.documentElement;
            r.classList.remove('theme-light', 'font-family-serif');
            r.classList.add('theme-' + p.theme, 'font-family-' + p.family);
            r.style.setProperty('--reader-size', p.size + 'px');
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @include('partials.pwa')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-full antialiased" x-data="reader"
    data-progress-url="{{ route('items.progress', $item) }}" data-progress="{{ (float) $item->read_progress }}">

    {{-- Top bar --}}
    <header class="reader-bar sticky top-0 z-30 border-b font-sans backdrop-blur">
        <div class="mx-auto flex h-14 max-w-3xl items-center justify-between gap-2 px-4">
            <a href="{{ route($item->isArchived() ? 'items.archive' : 'items.index') }}"
                class="reader-muted flex items-center gap-1 text-sm hover:opacity-80">
                <span aria-hidden="true">←</span> {{ __('Back') }}
            </a>

            <div class="flex items-center gap-1 text-sm">
                @if ($item->isArchived())
                    <form method="post" action="{{ route('items.archive.destroy', $item) }}">
                        @csrf
                        @method('delete')
                        <button type="submit" class="rounded-md px-2.5 py-1.5 hover:opacity-80">{{ __('Move to unread') }}</button>
                    </form>
                @else
                    <form method="post" action="{{ route('items.archive.store', $item) }}">
                        @csrf
                        <input type="hidden" name="then" value="list">
                        <button type="submit" class="rounded-md px-2.5 py-1.5 hover:opacity-80">{{ __('Archive') }}</button>
                    </form>
                @endif

                <form method="post" action="{{ $item->is_favourite ? route('items.favourite.destroy', $item) : route('items.favourite.store', $item) }}">
                    @csrf
                    @if ($item->is_favourite)
                        @method('delete')
                    @endif
                    <button type="submit" class="rounded-md px-2.5 py-1.5 hover:opacity-80"
                        aria-pressed="{{ $item->is_favourite ? 'true' : 'false' }}"
                        title="{{ $item->is_favourite ? __('Remove from favourites') : __('Add to favourites') }}">
                        {{ $item->is_favourite ? '★' : '☆' }}<span class="sr-only">{{ __('Favourite') }}</span>
                    </button>
                </form>

                <div class="relative">
                    <button type="button" @click="panel = ! panel" :aria-expanded="panel"
                        class="rounded-md px-2.5 py-1.5 font-serif hover:opacity-80" title="{{ __('Appearance') }}">
                        Aa<span class="sr-only">{{ __('Appearance') }}</span>
                    </button>

                    <div x-show="panel" x-cloak @click.outside="panel = false" @keydown.escape.window="panel = false"
                        class="reader-panel absolute right-0 mt-2 w-64 space-y-4 rounded-lg border p-4 shadow-lg">
                        <div>
                            <p class="reader-muted mb-2 text-xs font-semibold uppercase tracking-wide">{{ __('Text size') }}</p>
                            <div class="flex items-center justify-between">
                                <button type="button" @click="setSize(-1)" class="reader-border rounded-md border px-3 py-1 text-sm">A−</button>
                                <span class="reader-muted text-sm" x-text="prefs.size + 'px'"></span>
                                <button type="button" @click="setSize(1)" class="reader-border rounded-md border px-3 py-1 text-lg">A+</button>
                            </div>
                        </div>
                        <div>
                            <p class="reader-muted mb-2 text-xs font-semibold uppercase tracking-wide">{{ __('Typeface') }}</p>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="prefs.family = 'serif'" class="reader-border rounded-md border px-3 py-1.5 font-serif text-sm"
                                    :class="prefs.family === 'serif' && 'ring-2 ring-teal-600'">{{ __('Serif') }}</button>
                                <button type="button" @click="prefs.family = 'sans'" class="reader-border rounded-md border px-3 py-1.5 font-sans text-sm"
                                    :class="prefs.family === 'sans' && 'ring-2 ring-teal-600'">{{ __('Sans') }}</button>
                            </div>
                        </div>
                        <div>
                            <p class="reader-muted mb-2 text-xs font-semibold uppercase tracking-wide">{{ __('Theme') }}</p>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="prefs.theme = 'light'" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    :class="prefs.theme === 'light' && 'ring-2 ring-teal-600'">{{ __('Light') }}</button>
                                <button type="button" @click="prefs.theme = 'sepia'" class="reader-swatch-sepia rounded-md border border-amber-200 px-2 py-1.5 text-sm"
                                    :class="prefs.theme === 'sepia' && 'ring-2 ring-teal-600'">{{ __('Sepia') }}</button>
                                <button type="button" @click="prefs.theme = 'dark'" class="reader-swatch-dark rounded-md border border-slate-700 px-2 py-1.5 text-sm"
                                    :class="prefs.theme === 'dark' && 'ring-2 ring-teal-600'">{{ __('Dark') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-5 pb-24 pt-8 sm:px-6">
        @if (session('status'))
            <p class="reader-muted mb-6 font-sans text-sm" role="status">{{ session('status') }}</p>
        @endif

        <article>
            <header class="mb-8">
                <h1 class="reader-title text-3xl font-bold leading-tight sm:text-4xl">{{ $item->displayTitle() }}</h1>

                <p class="reader-muted mt-3 flex flex-wrap gap-x-2 font-sans text-sm">
                    <a href="{{ $item->originalUrl() }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2 hover:opacity-80">
                        {{ $item->site_name ?: $item->host() }} ↗
                    </a>
                    @if ($item->author)
                        <span aria-hidden="true">·</span><span>{{ $item->author }}</span>
                    @endif
                    @if ($item->published_at)
                        <span aria-hidden="true">·</span>
                        <time datetime="{{ $item->published_at->toIso8601String() }}">{{ $item->published_at->format('j F Y') }}</time>
                    @endif
                    @if ($item->reading_time > 0)
                        <span aria-hidden="true">·</span><span>{{ trans_choice(':count min read|:count min read', $item->reading_time) }}</span>
                    @endif
                </p>

                @if ($item->tags->isNotEmpty())
                    <p class="mt-3 flex flex-wrap gap-1.5 font-sans">
                        @foreach ($item->tags as $tag)
                            <a href="{{ route('items.tagged', $tag) }}" class="reader-muted reader-border rounded-full border px-2 py-0.5 text-xs hover:opacity-80">{{ $tag->name }}</a>
                        @endforeach
                    </p>
                @endif
            </header>

            @if ($item->hasArticle())
                @if ($item->refreshFailed())
                    <p class="reader-panel reader-muted mb-8 rounded-lg border p-4 font-sans text-sm" role="status">
                        {{ __('Could not refresh this article: :error You are reading the copy saved on :date.', [
                            'error' => rtrim((string) $item->fetch_error, '.').'.',
                            'date' => $item->fetched_at?->format('j F Y') ?? __('an earlier visit'),
                        ]) }}
                    </p>
                @endif
                <div class="reader-content">
                    {!! $item->content_html !!}
                </div>
            @else
                <div class="reader-panel rounded-lg border p-6 font-sans">
                    @if ($pending)
                        <p>{{ __('The article is still being fetched. Refresh in a moment.') }}</p>
                    @elseif ($failed)
                        <p>{{ $item->fetch_error ?: __('The article could not be fetched.') }}</p>
                    @endif

                    @if ($item->excerpt)
                        <p class="reader-muted mt-4">{{ $item->excerpt }}</p>
                    @endif

                    <div class="mt-5 flex flex-wrap items-center gap-4 text-sm">
                        <a href="{{ $item->originalUrl() }}" target="_blank" rel="noopener noreferrer"
                            class="font-semibold underline underline-offset-2">{{ __('Open the original') }} ↗</a>
                        @if ($failed)
                            <form method="post" action="{{ route('items.retry', $item) }}">
                                @csrf
                                <button type="submit" class="underline underline-offset-2">{{ __('Try again') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <footer class="reader-border mt-16 border-t pt-6 font-sans text-sm">
                <p class="reader-muted">
                    {{ __('Saved :date.', ['date' => $item->added_at?->format('j F Y')]) }}
                    <a href="{{ $item->originalUrl() }}" target="_blank" rel="noopener noreferrer" class="break-all underline underline-offset-2">{{ $item->originalUrl() }}</a>
                </p>

                <details class="mt-6">
                    <summary class="reader-muted cursor-pointer">{{ __('Edit title and tags') }}</summary>
                    <form method="post" action="{{ route('items.update', $item) }}" class="mt-4 space-y-3">
                        @csrf
                        @method('patch')
                        <div>
                            <label for="title" class="reader-muted block text-xs font-semibold uppercase tracking-wide">{{ __('Title') }}</label>
                            <input id="title" name="title" type="text" value="{{ $item->title }}"
                                class="mt-1 block w-full rounded-md border-slate-300 text-slate-900 shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <div>
                            <label for="tags" class="reader-muted block text-xs font-semibold uppercase tracking-wide">{{ __('Tags (comma-separated)') }}</label>
                            <input id="tags" name="tags" type="text" value="{{ $item->tags->pluck('name')->implode(', ') }}"
                                class="mt-1 block w-full rounded-md border-slate-300 text-slate-900 shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                    </form>

                    <form method="post" action="{{ route('items.destroy', $item) }}" class="mt-6"
                        data-confirm="{{ __('Delete this item? This cannot be undone.') }}">
                        @csrf
                        @method('delete')
                        <button type="submit" class="text-red-700 underline underline-offset-2">{{ __('Delete this item') }}</button>
                    </form>
                </details>
            </footer>
        </article>
    </main>
</body>

</html>
