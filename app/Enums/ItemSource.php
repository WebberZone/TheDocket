<?php

namespace App\Enums;

/**
 * How a link arrived. Recorded for the item's own sake (and the JSON
 * export); nothing branches on it except the title rule in ItemSaver.
 */
enum ItemSource: string
{
    case Web = 'web';
    case Bookmarklet = 'bookmarklet';
    case Share = 'share';
    case Api = 'api';
    case Pocket = 'pocket';
}
