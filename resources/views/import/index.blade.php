<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Import') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h2 class="text-lg font-medium text-slate-900">
                        {{ __('Import Accounts Tracker workbook') }}
                    </h2>

                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('Upload the legacy :file spreadsheet. Existing transactions are matched by date, vendor and GBP amount and skipped, so re-importing the same file is safe.', ['file' => 'Accounts Tracker.xlsx']) }}
                    </p>
                </header>

                <form method="post" action="{{ route('import.store') }}" enctype="multipart/form-data"
                    class="mt-6 space-y-6">
                    @csrf

                    <div>
                        <x-input-label for="tracker" :value="__('Workbook')" />
                        <input id="tracker" name="tracker" type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="mt-1 block w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" />
                        <x-input-error class="mt-2" :messages="$errors->get('tracker')" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button type="submit">{{ __('Import') }}</x-primary-button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>