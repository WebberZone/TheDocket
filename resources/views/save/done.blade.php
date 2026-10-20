{{--
    The bookmarklet's popup. Small and self-contained; closes itself after
    a successful save when it was opened as a popup.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $error ? __('Not saved') : __('Saved') }} — {{ config('app.name', 'Docket') }}</title>
    @vite(['resources/css/app.css'])
</head>

<body class="flex min-h-screen items-center justify-center bg-slate-100 p-6 font-sans text-slate-900 antialiased">
    <main class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
        <x-application-logo class="mx-auto h-10 w-10" />

        @if ($error)
            <h1 class="mt-4 text-lg font-semibold">{{ __('Not saved') }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ $error }}</p>
        @else
            <h1 class="mt-4 text-lg font-semibold">{{ $result->created ? __('Saved to Docket') : __('Already in Docket') }}</h1>
            <p class="mt-2 line-clamp-2 text-sm text-slate-600">{{ $result->item->displayTitle() }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $result->message() }}</p>
        @endif

        <div class="mt-5 flex justify-center gap-4 text-sm">
            <a href="{{ route('items.index') }}" target="_blank" class="font-medium text-teal-800 hover:underline">{{ __('Open Docket') }}</a>
            <button type="button" id="close-window" class="text-slate-600 hover:underline">{{ __('Close') }}</button>
        </div>
    </main>

    {{-- Inline (with the CSP nonce) rather than app.js: this popup loads no Alpine. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('close-window').addEventListener('click', function () {
            if (window.opener) {
                window.close();
            } else {
                history.back();
            }
        });
        @unless ($error)
            if (window.opener) {
                setTimeout(function () { window.close(); }, 1500);
            }
        @endunless
    </script>
</body>

</html>
