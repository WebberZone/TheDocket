<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const MAX_LENGTH = 100;

    protected $fillable = ['name', 'slug'];

    /**
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class);
    }

    /**
     * Tidy a tag name: trim, collapse whitespace, and strip the separators
     * (commas and Pocket's pipes) that would split it on the way back in.
     */
    public static function cleanName(string $name): string
    {
        $name = str_replace([',', '|'], ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, self::MAX_LENGTH);
    }

    /**
     * The case-insensitive identity of a tag name, used to match names, not
     * in URLs: a name may contain "/" or other characters a route can't
     * carry, so tags are routed by ID. Not Str::slug(): that
     * transliterates to ASCII and would turn a non-Latin tag into nothing.
     */
    public static function slugFor(string $name): string
    {
        return mb_strtolower(str_replace(' ', '-', self::cleanName($name)));
    }

    /**
     * Split a comma-separated (or array) tag list into clean, unique names.
     *
     * @param  string|iterable<mixed>|null  $tags
     * @return list<string>
     */
    public static function parseList(string|iterable|null $tags): array
    {
        if ($tags === null) {
            return [];
        }

        $raw = is_string($tags) ? explode(',', $tags) : $tags;

        $names = [];
        foreach ($raw as $name) {
            if (! is_scalar($name)) {
                continue;
            }

            $clean = self::cleanName((string) $name);

            if ($clean !== '') {
                $names[self::slugFor($clean)] ??= $clean;
            }
        }

        return array_values($names);
    }

    /**
     * Find or create a tag for each name and return their IDs.
     *
     * @param  iterable<string>  $names
     * @return list<int>
     */
    public static function idsForNames(iterable $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = self::cleanName($name);

            if ($name === '') {
                continue;
            }

            $tag = self::query()->firstOrCreate(
                ['slug' => self::slugFor($name)],
                ['name' => $name],
            );

            $ids[$tag->id] = $tag->id;
        }

        return array_values($ids);
    }
}
