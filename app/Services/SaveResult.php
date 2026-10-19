<?php

namespace App\Services;

use App\Models\Item;

final class SaveResult
{
    public function __construct(
        public readonly Item $item,
        public readonly bool $created,
    ) {}

    public function message(): string
    {
        return $this->created
            ? 'Saved. The article is being fetched.'
            : 'Already saved; moved it back to the top of your list.';
    }
}
