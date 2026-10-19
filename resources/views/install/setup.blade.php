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
         disabled entirely once the owner account has been created.
         Relative action, not route(): before .env exists the app defaults
         to APP_ENV=production and forces https URLs, which breaks installs
         served over plain http. --}}
    <form method="POST" action="/install/setup">
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

        @php
            // Fresh form: tick by default. Re-rendered after a validation
            // error: respect whatever the user submitted (an unticked box
            // posts nothing, so its absence means unticked).
            $seedLookups = array_key_exists('name', $input) ? ! empty($input['seed_lookups']) : true;
        @endphp
        <div class="checkbox-row">
            <input type="checkbox" id="seed_lookups" name="seed_lookups" value="1" @checked($seedLookups)>
            <span>
                <label for="seed_lookups" style="display:inline; margin:0;">Seed starter categories</label><br>
                A working example taxonomy (categories, business units and
                payment methods) you can rename or replace in Settings.
                Untick to start completely empty.
            </span>
        </div>

        <div class="actions">
            <button type="submit" class="button">Install Docket</button>
        </div>
    </form>
@endsection
