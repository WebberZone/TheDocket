<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

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
                                {{ __('Foundational or host-specific values that stay in config/docket.php or .env. The base currency is a core invariant: changing it at runtime would not recompute historical totals and would break the FX logic for new entries.') }}
                            </p>
                        </header>

                        <dl class="mt-6 space-y-3">
                            @foreach ($info as $label => $value)
                                <div class="flex justify-between text-sm">
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