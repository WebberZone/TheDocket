<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800">{{ __('Tags') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <x-input-error class="mb-4" :messages="$errors->get('name')" />

            @if ($tags->isEmpty())
                <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
                    {{ __('No tags yet. Add them when you save a link, in the reader, or with the bulk actions.') }}
                </div>
            @else
                <ul class="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    @foreach ($tags as $tag)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3" x-data="{ editing: false }">
                            <div class="min-w-0 flex-1" x-show="! editing">
                                <a href="{{ route('items.tagged', $tag) }}" class="font-medium text-slate-900 hover:text-teal-800">{{ $tag->name }}</a>
                                <span class="ml-2 text-sm text-slate-500">{{ trans_choice(':count item|:count items', $tag->items_count) }}</span>
                            </div>

                            <form x-show="editing" x-cloak method="post" action="{{ route('tags.update', $tag) }}" class="flex flex-1 items-center gap-2">
                                @csrf
                                @method('patch')
                                <label for="tag-{{ $tag->id }}" class="sr-only">{{ __('New name') }}</label>
                                <input id="tag-{{ $tag->id }}" name="name" type="text" value="{{ $tag->name }}" required
                                    class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                <x-primary-button class="py-1.5">{{ __('Rename') }}</x-primary-button>
                            </form>

                            <div class="flex items-center gap-4 text-sm">
                                <button type="button" @click="editing = ! editing" class="text-slate-600 hover:text-teal-800"
                                    x-text="editing ? @js(__('Cancel')) : @js(__('Rename'))">{{ __('Rename') }}</button>
                                <form method="post" action="{{ route('tags.destroy', $tag) }}"
                                    onsubmit="return confirm(@js(__('Delete this tag? The items stay; only the tag is removed.')))">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="text-slate-500 hover:text-red-700">{{ __('Delete') }}</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-sm text-slate-500">{{ __('Renaming a tag to the name of another one merges them.') }}</p>
            @endif
        </div>
    </div>
</x-app-layout>
