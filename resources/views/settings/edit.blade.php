<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Ways to save links --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-2xl space-y-8">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-slate-900">{{ __('Bookmarklet') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('Drag this button to your bookmarks bar. Click it on any page to save that page to Docket.') }}
                            </p>
                        </header>
                        <div class="mt-4 flex flex-wrap items-center gap-4">
                            <a href="{{ $bookmarklet }}" onclick="event.preventDefault(); alert(@js(__('Drag this button to your bookmarks bar rather than clicking it here.')))"
                                class="inline-flex items-center gap-2 rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-800">
                                <x-application-logo class="h-5 w-5" /> {{ __('Save to Docket') }}
                            </a>
                            <span class="text-sm text-slate-500">{{ __('On a phone, use the share sheet instead (below).') }}</span>
                        </div>
                        <details class="mt-3 text-sm text-slate-600">
                            <summary class="cursor-pointer">{{ __('Copy the code instead') }}</summary>
                            <p class="mt-2">{{ __('Create a bookmark by hand and paste this as its URL:') }}</p>
                            <textarea readonly rows="4" onclick="this.select()"
                                class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm">{{ $bookmarklet }}</textarea>
                        </details>
                    </section>

                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-slate-900">{{ __('Android: Share → Docket') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('Open Docket in Chrome on your phone and choose "Install app" (or "Add to Home screen") from the menu. Docket then appears in the share sheet of every app; sharing a link saves it.') }}
                            </p>
                        </header>
                    </section>

                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-slate-900">{{ __('API tokens') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('For the iOS Shortcut, browser extensions and scripts. Send a POST to :url with a JSON body like {"url": "https://…", "tags": ["later"]} and the header Authorization: Bearer <token>.', ['url' => $apiUrl]) }}
                            </p>
                        </header>

                        @if ($plainToken)
                            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                                <p class="text-sm font-medium text-amber-900">{{ __('Your new token. Copy it now: it will not be shown again.') }}</p>
                                <input type="text" readonly value="{{ $plainToken }}" onclick="this.select()"
                                    class="mt-2 block w-full rounded-md border-amber-300 bg-white font-mono text-sm shadow-sm">
                            </div>
                        @endif

                        <form method="post" action="{{ route('tokens.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="flex-1">
                                <x-input-label for="token_name" :value="__('Token name')" />
                                <x-text-input id="token_name" name="name" type="text" class="mt-1 block w-full"
                                    placeholder="{{ __('e.g. iPhone Shortcut') }}" required maxlength="100" />
                            </div>
                            <x-primary-button>{{ __('Create token') }}</x-primary-button>
                        </form>
                        <x-input-error class="mt-2" :messages="$errors->get('name')" />

                        @if ($tokens->isNotEmpty())
                            <ul class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200">
                                @foreach ($tokens as $token)
                                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                                        <div>
                                            <span class="font-medium text-slate-900">{{ $token->name }}</span>
                                            <span class="ml-2 text-slate-500">
                                                {{ __('created :date', ['date' => $token->created_at?->format('j M Y')]) }},
                                                {{ $token->last_used_at ? __('last used :when', ['when' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                                            </span>
                                        </div>
                                        <form method="post" action="{{ route('tokens.destroy', $token->id) }}"
                                            onsubmit="return confirm(@js(__('Revoke this token? Anything using it will stop working.')))">
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="text-slate-500 hover:text-red-700">{{ __('Revoke') }}</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <details class="mt-4 text-sm text-slate-600">
                            <summary class="cursor-pointer font-medium text-slate-700">{{ __('Setting up the iOS Shortcut') }}</summary>
                            <ol class="mt-3 list-decimal space-y-2 pl-5">
                                <li>{{ __('Create a token above and copy it.') }}</li>
                                <li>{{ __('In the Shortcuts app, create a new shortcut. In its details, turn on "Show in Share Sheet" and set it to receive URLs.') }}</li>
                                <li>{{ __('Add the action "Get Contents of URL". Set the URL to :url, the method to POST, and add two headers: Authorization = Bearer followed by a space and your token, and Accept = application/json.', ['url' => $apiUrl]) }}</li>
                                <li>{{ __('Set the request body to JSON, with one text field: url = the Shortcut Input.') }}</li>
                                <li>{{ __('Optionally add "Show Notification" with the text "Saved to Docket". Name it "Save to Docket".') }}</li>
                            </ol>
                        </details>
                    </section>
                </div>
            </div>

            {{-- Editable knobs --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-slate-900">
                                {{ __('Application settings') }}
                            </h2>

                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('Tunable knobs that override the defaults in config/docket.php.') }}
                            </p>
                        </header>

                        <form method="post" action="{{ route('settings.update') }}" class="mt-6 space-y-6">
                            @csrf
                            @method('patch')

                            @foreach ($fields as $key => $field)
                                {{-- PHP mangles dots in form data, so the field is named
                                     by the underscore form key, not the dotted config path. --}}
                                @php($name = \App\Services\Settings::formKey($key))
                                <div>
                                    <x-input-label for="{{ $name }}" :value="__($field['label'])" />
                                    <x-text-input id="{{ $name }}" name="{{ $name }}" type="text" class="mt-1 block w-full"
                                        :value="old($name, $settings->forInput($key))" required />
                                    <p class="mt-1 text-sm text-slate-500">{{ __($field['help']) }}</p>
                                    <x-input-error class="mt-2" :messages="$errors->get($name)" />
                                </div>
                            @endforeach

                            <div class="flex items-center gap-4">
                                <x-primary-button>{{ __('Save') }}</x-primary-button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            {{-- Read-only invariants --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-slate-900">
                                {{ __('Info') }}
                            </h2>

                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('Host-specific values that live in .env or config/docket.php.') }}
                            </p>
                        </header>

                        <dl class="mt-6 space-y-3">
                            @foreach ($info as $label => $value)
                                <div class="flex justify-between gap-4 text-sm">
                                    <dt class="text-slate-600">{{ __($label) }}</dt>
                                    <dd class="font-medium text-slate-900 text-right break-all">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
