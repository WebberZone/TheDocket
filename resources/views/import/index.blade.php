<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800">{{ __('Import & export') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-medium text-slate-900">{{ __('Import from Pocket') }}</h3>
                <p class="mt-1 text-sm text-slate-600">
                    {{ __('Upload the CSV from Pocket\'s export (columns: title, url, time_added, tags, status). Each link keeps its saved date, tags and unread or archived status. Importing the same file again is safe: links already here are skipped, apart from gaining any tags they were missing.') }}
                </p>
                <p class="mt-2 text-sm text-slate-600">
                    {{ __('Articles are fetched in the background, one every :seconds seconds, so a big import takes a while to fill in. Keep the queue worker running.', ['seconds' => settings('import.spacing_seconds')]) }}
                </p>

                <form method="post" action="{{ route('import.store') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label for="file" class="block text-sm font-medium text-slate-700">{{ __('Pocket CSV') }}</label>
                        <input id="file" name="file" type="file" accept=".csv,text/csv" required
                            class="mt-1 block w-full text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                        <p class="mt-1 text-xs text-slate-500">{{ __('Up to :size MB. Command-line alternative: php artisan docket:import-pocket path/to/file.csv', ['size' => round($maxKb / 1024)]) }}</p>
                        <x-input-error class="mt-2" :messages="$errors->get('file')" />
                    </div>
                    <x-primary-button>{{ __('Import') }}</x-primary-button>
                </form>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-medium text-slate-900">{{ __('Export') }}</h3>
                <p class="mt-1 text-sm text-slate-600">
                    {{ trans_choice('Your library has :count item.|Your library has :count items.', $itemCount) }}
                </p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li>
                        <a href="{{ route('export.csv') }}" class="font-medium text-teal-800 hover:underline">{{ __('Download CSV') }}</a>
                        <span class="text-slate-600">— {{ __('the same columns as Pocket\'s export, so it imports back into Docket or anything that reads Pocket files.') }}</span>
                    </li>
                    <li>
                        <a href="{{ route('export.json') }}" class="font-medium text-teal-800 hover:underline">{{ __('Download JSON') }}</a>
                        <span class="text-slate-600">— {{ __('everything, including the saved article text and HTML.') }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
