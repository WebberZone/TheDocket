<?php

namespace App\Enums;

enum ItemStatus: string
{
    case Unread = 'unread';
    case Archived = 'archived';

    /**
     * The status word Pocket's export uses for this state.
     */
    public function pocketName(): string
    {
        return match ($this) {
            self::Unread => 'unread',
            self::Archived => 'archive',
        };
    }

    public static function fromPocket(string $value): self
    {
        return in_array(strtolower(trim($value)), ['archive', 'archived', 'read'], true)
            ? self::Archived
            : self::Unread;
    }
}
