<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docket Application Settings
    |--------------------------------------------------------------------------
    |
    | App policy for the read-later pipeline. Most of this is policy rather
    | than per-deployment secrets, so it lives here (not .env). The values
    | that genuinely differ per host are pulled from DOCKET_* variables, and
    | the knobs listed in App\Services\Settings::fields() can also be
    | overridden at runtime from the Settings page.
    |
    */

    /*
    | The single owner account. There is no registration flow: the installer
    | (php artisan docket:install or /install) creates the account directly,
    | and these are the fallbacks DatabaseSeeder uses if the database is ever
    | re-seeded from scratch. Once the user exists the database is the source
    | of truth, so changing these afterwards has no effect on the live login.
    */
    'user' => [
        // ?: not the env() default param: a present-but-empty line in .env
        // (e.g. DOCKET_USER_NAME=) would otherwise seed an empty string.
        'name' => env('DOCKET_USER_NAME') ?: 'Docket Owner',
        'email' => env('DOCKET_USER_EMAIL') ?: 'owner@example.com',
        'password' => env('DOCKET_USER_PASSWORD') ?: 'change-me-on-first-login',
    ],

    /*
    | Fetching saved pages. Every request goes through App\Services\SafeHttpClient,
    | which refuses private, loopback and link-local addresses after DNS
    | resolution, follows redirects itself (re-checking each hop) and stops
    | reading once a body passes the size cap.
    */
    'fetch' => [
        'user_agent' => env(
            'DOCKET_USER_AGENT',
            'Mozilla/5.0 (compatible; Docket/1.0; +https://github.com/WebberZone/TheDocket)'
        ),
        // Seconds.
        'timeout' => 20,
        'connect_timeout' => 10,
        'max_redirects' => 5,
        // Largest HTML page read, in kilobytes (5 MB).
        'max_kb' => 5120,
        // Queued job retries; backoff is the seconds to wait between tries.
        'tries' => 3,
        'backoff' => [30, 300],
    ],

    /*
    | Inline images are downloaded to private storage so saved articles
    | survive link rot. SVG is never stored locally: it can carry scripts and
    | the copies are served from this origin.
    */
    'images' => [
        'enabled' => true,
        // Per image, in kilobytes (5 MB).
        'max_kb' => 5120,
        // Images per article; the rest keep their remote src.
        'max_per_item' => 40,
    ],

    /*
    | Reading time is word count divided by this, rounded up.
    */
    'words_per_minute' => 230,

    /*
    | Items per page on the lists.
    */
    'per_page' => 30,

    /*
    | Pocket import: extraction jobs are spaced this many seconds apart so a
    | few thousand saved links don't hammer the sites (or the queue worker)
    | all at once.
    */
    'import' => [
        'spacing_seconds' => 10,
        // Upload cap for the CSV, in kilobytes (20 MB).
        'max_kb' => 20480,
    ],

];
