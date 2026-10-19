<?php

use App\Services\Settings;

/**
 * Resolve the Settings service, or read a single override.
 *
 *  settings()                  -> Settings instance
 *  settings('receipts.max_kb') -> DB override or config('docket.receipts.max_kb')
 *  settings('foo', 20480)      -> override, else 20480
 */
function settings(?string $key = null, mixed $default = null): mixed
{
    $service = app(Settings::class);

    if ($key === null) {
        return $service;
    }

    return $service->get($key, $default);
}
