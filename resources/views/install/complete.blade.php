@extends('install.layout')

@section('content')
    <h2>Docket is installed 🎉</h2>
    <p>
        Your account <strong>{{ $email }}</strong> is ready and the installer
        is now disabled. A few things to finish on the server:
    </p>

    <ol class="steps">
        @unless ($assetsBuilt)
            <li>
                Build the frontend assets:
                <code>npm install &amp;&amp; npm run build</code>
            </li>
        @endunless
        <li>
            Run a queue worker for receipt OCR —
            <code>php artisan queue:work</code>
            (under systemd or supervisor in production).
        </li>
        <li>
            Add one cron entry for the scheduler (backups &amp; cleanup):
            <code>* * * * * cd /path/to/docket &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
        </li>
        <li>
            Optional receipt OCR: set <code>GOOGLE_VISION_CREDENTIALS</code>
            in <code>.env</code>, or install <code>tesseract</code> for the
            free local fallback. With neither, uploads simply skip extraction.
        </li>
        <li>
            Optional off-site backups: fill in the <code>BACKUP_*</code> keys
            in <code>.env</code> (any S3-compatible bucket).
        </li>
        <li>
            In production, cache the config once <code>.env</code> is final:
            <code>php artisan config:cache</code>
        </li>
    </ol>

    <div class="actions">
        {{-- Relative path: this page renders in the same request that
             created .env, so the URL generator may still think it is in
             production and force https. --}}
        <a class="button" href="/login">Log in to Docket</a>
    </div>
@endsection
