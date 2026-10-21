{{-- PWA: manifest + icons + service worker registration. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f766e">
<link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
@auth
    {{-- Logged in: resources/js/offline.js keeps the offline copies in step. --}}
    <meta name="docket-offline" content="{{ route('offline.articles', [], false) }}">
@endauth
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
    }
</script>
