@extends('install.layout')

@section('content')
    <h2>Docket is installed</h2>
    <p>
        Your account <strong>{{ $email }}</strong> is ready and the installer
        is now disabled. One thing left to do on the server:
    </p>

    <ol class="steps">
        <li>
            <p>
                <strong>Add this one cron line</strong> (in your control
                panel's Cron Jobs section, or with <code>crontab -e</code>
                over SSH). It fetches saved pages, runs backups and keeps
                Docket's housekeeping going:
            </p>
            <div class="cron-line">
                <code id="docket-cron-line">{{ $cronLine }}</code>
                <button type="button" class="copy-button" data-copy-target="#docket-cron-line">Copy</button>
            </div>
            <p class="hint">
                Until it first runs, a warning banner shows at the top of
                every page. It disappears within a minute of the cron
                running.
            </p>
        </li>

        @unless ($assetsBuilt)
            <li>
                Build the frontend assets:
                <code>npm install &amp;&amp; npm run build</code>
            </li>
        @endunless

        <li>
            Optional off-site backups: fill in the <code>BACKUP_*</code>
            keys in <code>.env</code> (any S3-compatible bucket).
        </li>
        <li>
            Optional: for pages to be fetched the instant they are saved,
            run a dedicated worker (<code>php artisan queue:work</code>
            under systemd or supervisor) and set
            <code>DOCKET_QUEUE_VIA_SCHEDULER=false</code> in
            <code>.env</code>. The cron line alone is enough otherwise.
        </li>
    </ol>

    <div class="actions">
        {{-- Relative path: this page renders in the same request that
             created .env, so the URL generator may still think it is in
             production and force https. --}}
        <a class="button" href="/login">Log in to Docket</a>
    </div>
@endsection
