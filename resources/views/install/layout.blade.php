{{--
    Standalone layout for the web installer. Deliberately self-contained:
    no Vite/app.css (the frontend may not be built yet), no session, no
    components from the authenticated app.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Install Docket</title>
    <link rel="stylesheet" href="/install.css">
</head>
<body>
    <main>
        <div class="brand">
            <h1>Docket</h1>
            <p>Self-hosted read-later</p>
        </div>
        <div class="card">
            @yield('content')
        </div>
        <footer>Open source under the MIT licence</footer>
    </main>
    {{-- Relative path again, like the wizard's links. Served by 'self'
         under the CSP, so no nonce is needed. --}}
    <script src="/install.js" defer></script>
</body>
</html>
