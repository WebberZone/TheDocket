{{-- PWA: manifest + icons + service worker registration. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#4f46e5">
<link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
    }
</script>
