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
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            line-height: 1.55;
            padding: 2.5rem 1rem 4rem;
        }
        main { max-width: 40rem; margin: 0 auto; }
        .brand { text-align: center; margin-bottom: 1.75rem; }
        .brand h1 { font-size: 1.9rem; letter-spacing: -0.02em; }
        .brand p { color: #64748b; font-size: 0.95rem; }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgb(15 23 42 / 0.08);
            padding: 2rem;
        }
        .card h2 { font-size: 1.2rem; margin-bottom: 0.75rem; }
        .card p { margin-bottom: 1rem; color: #334155; }
        ul.checks { list-style: none; margin: 1.25rem 0; }
        ul.checks li {
            display: flex; gap: 0.6rem; align-items: baseline;
            padding: 0.45rem 0; border-bottom: 1px solid #f1f5f9;
        }
        .badge {
            flex: none; font-size: 0.72rem; font-weight: 700; border-radius: 999px;
            padding: 0.1rem 0.55rem; text-transform: uppercase; letter-spacing: 0.03em;
        }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.fail { background: #fee2e2; color: #991b1b; }
        .badge.warn { background: #fef3c7; color: #92400e; }
        .hint { display: block; color: #64748b; font-size: 0.85rem; }
        .banner {
            border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem;
            font-size: 0.92rem;
        }
        .banner.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .banner.notice { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        label { display: block; font-weight: 600; font-size: 0.9rem; margin: 1rem 0 0.25rem; }
        input[type=text], input[type=email], input[type=password], input[type=url] {
            width: 100%; padding: 0.55rem 0.75rem; font-size: 1rem;
            border: 1px solid #cbd5e1; border-radius: 0.5rem; background: #fff; color: inherit;
        }
        input:focus { outline: 2px solid #0f172a; outline-offset: 1px; }
        .field-error { color: #b91c1c; font-size: 0.85rem; margin-top: 0.25rem; }
        .checkbox-row { display: flex; gap: 0.6rem; align-items: flex-start; margin-top: 1.25rem; }
        .checkbox-row input { margin-top: 0.3rem; }
        .checkbox-row span { font-size: 0.92rem; color: #334155; }
        .actions { margin-top: 1.75rem; }
        .button {
            display: inline-block; background: #0f172a; color: #fff; border: 0;
            font-size: 1rem; font-weight: 600; padding: 0.65rem 1.5rem;
            border-radius: 0.5rem; cursor: pointer; text-decoration: none;
        }
        .button:hover { background: #1e293b; }
        ol.steps { margin: 1rem 0 1rem 1.25rem; }
        ol.steps li { margin-bottom: 0.6rem; }
        code {
            background: #f1f5f9; border-radius: 0.3rem; padding: 0.1rem 0.35rem;
            font-size: 0.88em; word-break: break-all;
        }
        footer { text-align: center; color: #94a3b8; font-size: 0.85rem; margin-top: 1.5rem; }
        footer a { color: inherit; }
    </style>
</head>
<body>
    <main>
        <div class="brand">
            <h1>Docket</h1>
            <p>Self-hosted expense &amp; receipt tracking</p>
        </div>
        <div class="card">
            @yield('content')
        </div>
        <footer>Open source under the MIT licence</footer>
    </main>
</body>
</html>
