@extends('install.layout')

@section('content')
    <h2>Set up Docket</h2>
    <p>
        Docket is a single-user app: this creates the one account you will
        log in with (there is no registration screen).
    </p>

    @if ($installError)
        <div class="banner error">
            Installation failed: {{ $installError }}
        </div>
    @endif

    {{-- No @csrf: these routes run before the session store exists and are
         disabled entirely once the owner account has been created. The
         setup code does the job CSRF would: a request that can't read the
         server's files can't supply it.
         Relative action, not route(): before .env exists the app defaults
         to APP_ENV=production and forces https URLs, which breaks installs
         served over plain http. --}}
    <form method="POST" action="/install/setup">
        <label for="setup_code">Setup code</label>
        <input type="text" id="setup_code" name="setup_code" required autocomplete="off" spellcheck="false"
               value="{{ $input['setup_code'] ?? '' }}">
        <span class="hint">
            Proves you control the server. Read it there:
            <code>cat {{ $codeLocation }}</code>
        </span>
        @if ($errors->has('setup_code'))
            <p class="field-error">{{ $errors->first('setup_code') }}</p>
        @endif

        <label for="url">Site URL</label>
        <input type="url" id="url" name="url" required
               value="{{ $input['url'] ?? '' }}">
        @if ($errors->has('url'))
            <p class="field-error">{{ $errors->first('url') }}</p>
        @endif

        <label for="name">Your name</label>
        <input type="text" id="name" name="name" required
               value="{{ $input['name'] ?? '' }}">
        @if ($errors->has('name'))
            <p class="field-error">{{ $errors->first('name') }}</p>
        @endif

        <label for="email">Email (used to log in)</label>
        <input type="email" id="email" name="email" required
               value="{{ $input['email'] ?? '' }}">
        @if ($errors->has('email'))
            <p class="field-error">{{ $errors->first('email') }}</p>
        @endif

        <label for="password">Password (minimum 8 characters)</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        @if ($errors->has('password'))
            <p class="field-error">{{ $errors->first('password') }}</p>
        @endif

        <label for="password_confirmation">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">

        <div class="actions">
            <button type="submit" class="button">Install Docket</button>
        </div>
    </form>
@endsection
