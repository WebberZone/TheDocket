@extends('install.layout')

@section('content')
    <h2>Docket is installed</h2>
    <p>
        Your account <strong>{{ $email }}</strong> is ready and the installer
        is now disabled. Docket works as it is — articles are fetched when
        you save them — but backups, Pocket imports and bulk refetches need
        background tasks set up. Two options, either of which is fine:
    </p>

    <ol class="steps">
        <li>
            <p>
                <strong>Recommended: add this cron line</strong> (your control
                panel's Cron Jobs, or <code>crontab -e</code> over SSH):
            </p>
            <div class="cron-line">
                <code id="docket-cron-line">{{ $cronLine }}</code>
                <button type="button" class="copy-button" data-copy-target="#docket-cron-line">Copy</button>
            </div>
        </li>
        <li>
            <p>
                <strong>No crontab access?</strong> The Settings page shows a
                secret <strong>cron URL</strong> — point a free web-cron
                service (e.g. cron-job.org) at it once a minute.
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
