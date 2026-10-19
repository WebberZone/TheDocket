<?php

namespace App\Services;

final class ImportResult
{
    public int $created = 0;

    public int $existing = 0;

    public int $invalid = 0;

    public int $queued = 0;

    public function summary(): string
    {
        return sprintf(
            'Imported %d new %s; %d already saved; %d skipped as invalid. %d queued for extraction.',
            $this->created,
            $this->created === 1 ? 'link' : 'links',
            $this->existing,
            $this->invalid,
            $this->queued,
        );
    }
}
