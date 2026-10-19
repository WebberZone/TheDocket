<?php

namespace App\Enums;

enum FetchStatus: string
{
    case Pending = 'pending';
    case Fetched = 'fetched';
    case Failed = 'failed';
}
