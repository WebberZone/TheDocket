<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-slate-800">
                    @if ($view === 'tag')
                        <span class="text-slate-500">{{ __('Tag:') }}</span>
                    @endif
                    {{ $heading }}
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    @if ($q !== '')
                        {{ trans_choice(':count result for “:q”|:count results for “:q”', $items->total(), ['q' => $q]) }}
                    @else
                        {{ trans_choice(':count item|:count items', $items->total()) }}
                    @endif
                </p>
            </div>

            <form method="get" action="{{ $view === 'search' ? route('items.search') : url()->current() }}"
                class="flex flex-wrap items-center gap-2">
                <label for="q" class="sr-only">{{ __('Search') }}</label>
                <input id="q" name="q" type="search" value="{{ $q }}"
                    placeholder="{{ $view === 'search' ? __('Search everything') : __('Search this list') }}"
                    class="w-56 rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">

                <label for="sort" class="sr-only">{{ __('Sort') }}</label>
                <select id="sort" name="sort" onchange="this.form.submit()"
                    class="rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    @if ($q !== '')
                        <option value="" @selected($sort === 'relevance')>{{ __('Most relevant') }}</option>
                    @endif
                    @foreach (\App\Models\Item::SORTS as $value => $label)
                        <option value="{{ $value }}" @selected($sort === $value)>{{ __($label) }}</option>
                    @endforeach
                </select>
                <x-secondary-button type="submit">{{ __('Go') }}</x-secondary-button>
            </form>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Save a link --}}
            <form method="post" action="{{ route('items.store') }}"
                class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                @csrf
                <div class="flex flex-col gap-3 sm:flex-row">
                    <div class="flex-1">
                        <label for="url" class="sr-only">{{ __('URL') }}</label>
                        <input id="url" name="url" type="text" inputmode="url" required
                            value="{{ old('url') }}" placeholder="{{ __('Paste a link to save') }}"
                            class="block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div class="sm:w-56">
                        <label for="tags" class="sr-only">{{ __('Tags') }}</label>
                        <input id="tags" name="tags" type="text" value="{{ old('tags', $view === 'tag' && $tag ? $tag->name : '') }}"
                            placeholder="{{ __('Tags (comma-separated)') }}"
                            class="block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <x-primary-button class="justify-center">{{ __('Save') }}</x-primary-button>
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('url')" />
            </form>

            @if ($items->isEmpty())
                <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
                    @if ($q !== '')
                        {{ __('Nothing matches that search.') }}
                    @elseif ($view === 'unread')
                        {{ __('Nothing to read. Save a link above, use the bookmarklet, or share to Docket from your phone.') }}
                    @elseif ($view === 'archive')
                        {{ __('Nothing archived yet.') }}
                    @elseif ($view === 'favourites')
                        {{ __('No favourites yet.') }}
                    @else
                        {{ __('Nothing here.') }}
                    @endif
                </div>
            @else
                <div x-data="{
                        selected: [],
                        ids: @js($items->pluck('id')->map(fn ($id) => (string) $id)->values()),
                        get all() { return this.selected.length === this.ids.length },
                        toggleAll() { this.selected = this.all ? [] : [...this.ids] },
                    }">

                    {{-- Bulk actions: the checkboxes below join this form via form="bulk". --}}
                    <form id="bulk" method="post" action="{{ route('items.bulk') }}"
                        x-data="{ action: '' }"
                        @submit="if (action === 'delete' && ! confirm(@js(__('Delete the selected items? This cannot be undone.')))) $event.preventDefault()"
                        class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                        @csrf
                        <label class="flex items-center gap-2 text-slate-600">
                            <input type="checkbox" class="rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                                :checked="all" @change="toggleAll()">
                            {{ __('Select all') }}
                        </label>

                        <template x-if="selected.length > 0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-slate-500" x-text="selected.length + ' selected'"></span>
                                <label for="bulk-action" class="sr-only">{{ __('Action') }}</label>
                                <select id="bulk-action" name="action" x-model="action" required
                                    class="rounded-md border-slate-300 py-1 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                    <option value="">{{ __('Choose an action…') }}</option>
                                    @foreach (\App\Http\Controllers\BulkItemController::ACTIONS as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <input x-show="action === 'tag'" name="tags" type="text" placeholder="{{ __('Tags to add') }}"
                                    class="rounded-md border-slate-300 py-1 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                <x-secondary-button type="submit" class="py-1">{{ __('Apply') }}</x-secondary-button>
                            </div>
                        </template>
                    </form>
                    <x-input-error class="mb-3" :messages="array_merge($errors->get('ids'), $errors->get('action'), $errors->get('tags'))" />

                    <ul class="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        @foreach ($items as $item)
                            @include('items.partials.row', ['item' => $item])
                        @endforeach
                    </ul>
                </div>

                <div>
                    {{ $items->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
