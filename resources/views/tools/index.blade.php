<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Tools') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Output from the last tool run, flashed by ToolsController::run() --}}
            @if (session('tool_ran'))
                <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg">
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-medium text-slate-900">
                            {{ session('tool_ran') }}
                        </h3>
                        @if (session('tool_exit_code') === 0)
                            <span
                                class="text-xs font-semibold uppercase tracking-wide text-green-700 bg-green-100 rounded px-2 py-0.5">{{ __('Success') }}</span>
                        @else
                            <span
                                class="text-xs font-semibold uppercase tracking-wide text-red-700 bg-red-100 rounded px-2 py-0.5">{{ __('Failed (exit :code)', ['code' => session('tool_exit_code')]) }}</span>
                        @endif
                    </div>
                    <pre
                        class="mt-3 p-4 bg-slate-900 text-white font-mono text-sm rounded overflow-x-auto whitespace-pre-wrap border border-slate-800">{{ session('tool_output') }}</pre>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h2 class="text-lg font-medium text-slate-900">
                        {{ __('Maintenance tools') }}
                    </h2>

                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('Each button runs one artisan command on the server and shows its output above. Everything here is also available from the command line, e.g. php artisan docket:ocr.') }}
                    </p>
                </header>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    @foreach ($tools as $key => $tool)
                        <div class="border border-slate-200 rounded-lg p-4 flex flex-col">
                            <h3 class="font-medium text-slate-900">{{ __($tool['label']) }}</h3>
                            <p class="mt-1 text-sm text-slate-600 flex-1">{{ __($tool['description']) }}</p>
                            <form method="post" action="{{ route('tools.run', $key, false) }}" class="mt-3">
                                @csrf
                                <x-secondary-button type="submit">{{ __('Run') }}</x-secondary-button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</x-app-layout>