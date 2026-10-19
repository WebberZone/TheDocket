<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            // 'array' (not 'json') so null/empty values don't choke the cast.
            'value' => 'array',
        ];
    }
}
