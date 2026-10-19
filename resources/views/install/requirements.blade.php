@extends('install.layout')

@section('content')
    <h2>Welcome to the Docket installer</h2>
    <p>
        This wizard checks your server, creates the configuration file and
        database, and sets up your login. It only works while Docket is not
        yet installed — once your account exists it disables itself.
    </p>

    <ul class="checks">
        @foreach ($checks as $check)
            <li>
                @if ($check['ok'])
                    <span class="badge ok">Pass</span>
                @elseif ($check['blocking'])
                    <span class="badge fail">Fail</span>
                @else
                    <span class="badge warn">Later</span>
                @endif
                <span>
                    {{ $check['label'] }}
                    @unless ($check['ok'])
                        <span class="hint">{{ $check['hint'] }}</span>
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>

    @if ($canProceed)
        <p>
            Items marked <strong>Later</strong> are optional and can be sorted
            out after installation.
        </p>
        <div class="actions">
            {{-- Relative path, not route(): before .env exists the app
                 defaults to APP_ENV=production and forces https URLs,
                 which breaks installs served over plain http. --}}
            <a class="button" href="/install/setup">Continue</a>
        </div>
    @else
        <div class="banner error">
            Fix the failed requirements above, then refresh this page.
        </div>
    @endif
@endsection
