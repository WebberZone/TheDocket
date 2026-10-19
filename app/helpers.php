<?php

use App\Services\Settings;

/**
 * Resolve the Settings service, or read a single override.
 *
 *  settings()                  -> Settings instance
 *  settings('fetch.timeout')   -> DB override or config('docket.fetch.timeout')
 *  settings('foo', 20)         -> override, else 20
 */
function settings(?string $key = null, mixed $default = null): mixed
{
    $service = app(Settings::class);

    if ($key === null) {
        return $service;
    }

    return $service->get($key, $default);
}
