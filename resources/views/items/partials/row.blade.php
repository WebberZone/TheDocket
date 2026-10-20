@php
    /** @var \App\Models\Item $item */
    $failed = $item->fetch_status === \App\Enums\FetchStatus::Failed;
    // fetched_at is only set by a successful fetch, so a failed item that
    // has one still has its saved copy (lists don't load article bodies).
    $refreshFailed = $failed && $item->fetched_at !== null;
    $pending = $item->fetch_status === \App\Enums\FetchStatus::Pending;
@endphp
<li class="flex gap-3 px-4 py-4 sm:px-5">
    <div class="pt-1">
        <input type="checkbox" name="ids[]" value="{{ $item->id }}" form="bulk" x-model="selected"
            aria-label="{{ __('Select :title', ['title' => $item->displayTitle()]) }}"
            class="rounded border-slate-300 text-teal-700 focus:ring-teal-500">
    </div>

    <div class="min-w-0 flex-1">
        <a href="{{ route('items.show', $item) }}" class="block font-semibold text-slate-900 hover:text-teal-800">
            {{ $item->displayTitle() }}
        </a>

        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
            <span>{{ $item->site_name ?: $item->host() }}</span>
            @if ($item->reading_time > 0)
                <span aria-hidden="true">·</span>
                <span>{{ trans_choice(':count min|:count min', $item->reading_time) }}</span>
            @endif
            <span aria-hidden="true">·</span>
            <time datetime="{{ $item->added_at?->toIso8601String() }}" title="{{ $item->added_at?->format('j F Y, H:i') }}">
                {{ $item->added_at?->diffForHumans() }}
            </time>
            @if ($item->read_progress > 0.02 && $item->read_progress < 0.98)
                <span aria-hidden="true">·</span>
                <span>{{ __(':percent% read', ['percent' => (int) round($item->read_progress * 100)]) }}</span>
            @endif
            @if ($pending)
                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{{ __('Fetching…') }}</span>
            @elseif ($failed)
                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800" title="{{ $item->fetch_error }}">
                    {{ $refreshFailed ? __('Refresh failed') : __('Could not fetch') }}
                </span>
            @endif
            @if ($item->isArchived())
                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{{ __('Archived') }}</span>
            @endif
        </div>

        @if ($item->excerpt)
            <p class="mt-2 line-clamp-2 text-sm text-slate-600">{{ $item->excerpt }}</p>
        @endif

        @if ($failed && ! $refreshFailed && $item->fetch_error)
            <p class="mt-2 text-sm text-amber-800">{{ $item->fetch_error }}</p>
        @endif

        @if ($item->tags->isNotEmpty())
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($item->tags as $tag)
                    <a href="{{ route('items.tagged', $tag) }}"
                        class="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-800 hover:bg-teal-100">{{ $tag->name }}</a>
                @endforeach
            </div>
        @endif

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            @if ($item->isArchived())
                <form method="post" action="{{ route('items.archive.destroy', $item) }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="text-slate-600 hover:text-teal-800">{{ __('Move to unread') }}</button>
                </form>
            @else
                <form method="post" action="{{ route('items.archive.store', $item) }}">
                    @csrf
                    <button type="submit" class="text-slate-600 hover:text-teal-800">{{ __('Archive') }}</button>
                </form>
            @endif

            <form method="post" action="{{ $item->is_favourite ? route('items.favourite.destroy', $item) : route('items.favourite.store', $item) }}">
                @csrf
                @if ($item->is_favourite)
                    @method('delete')
                @endif
                <button type="submit" class="{{ $item->is_favourite ? 'text-amber-600' : 'text-slate-600' }} hover:text-teal-800"
                    aria-pressed="{{ $item->is_favourite ? 'true' : 'false' }}">
                    {{ $item->is_favourite ? __('★ Favourite') : __('☆ Favourite') }}
                </button>
            </form>

            @if ($failed)
                <form method="post" action="{{ route('items.retry', $item) }}">
                    @csrf
                    <button type="submit" class="text-slate-600 hover:text-teal-800">{{ __('Try again') }}</button>
                </form>
            @endif

            <a href="{{ $item->originalUrl() }}" target="_blank" rel="noopener noreferrer"
                class="text-slate-600 hover:text-teal-800">{{ __('Original') }} ↗</a>

            <form method="post" action="{{ route('items.destroy', $item) }}"
                data-confirm="{{ __('Delete this item? This cannot be undone.') }}">
                @csrf
                @method('delete')
                <button type="submit" class="text-slate-500 hover:text-red-700">{{ __('Delete') }}</button>
            </form>
        </div>
    </div>

    @if ($item->lead_image_path)
        <a href="{{ route('items.show', $item) }}" class="hidden shrink-0 sm:block" tabindex="-1" aria-hidden="true">
            <img src="{{ route('items.image', ['item' => $item, 'filename' => $item->lead_image_path], false) }}" alt=""
                loading="lazy" class="h-20 w-28 rounded-md object-cover">
        </a>
    @endif
</li>
