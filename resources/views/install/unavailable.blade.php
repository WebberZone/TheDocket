@extends('install.layout')

@section('content')
    <h2>The installer is unavailable</h2>
    <div class="banner error">{{ $message }}</div>
    <p>
        Docket couldn't confirm whether it is already installed, so it won't
        offer to install itself. Fix the database connection (or its file
        permissions), then reload this page.
    </p>
@endsection
