<?php

namespace App\Models;

use App\Enums\FetchStatus;
use App\Enums\ItemSource;
use App\Enums\ItemStatus;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A saved link and, once the fetch job has run, its extracted article.
 *
 * @property int $id
 * @property string $url
 * @property string $normalized_url
 * @property string|null $resolved_url
 * @property string|null $title
 * @property string|null $author
 * @property string|null $site_name
 * @property string|null $excerpt
 * @property string|null $lead_image_url
 * @property string|null $lead_image_path
 * @property string|null $content_html
 * @property string|null $content_text
 * @property int $word_count
 * @property int $reading_time
 * @property string|null $language
 * @property Carbon|null $published_at
 * @property ItemStatus $status
 * @property Carbon|null $archived_at
 * @property bool $is_favourite
 * @property Carbon|null $favourited_at
 * @property float $read_progress
 * @property Carbon|null $last_read_at
 * @property ItemSource $source
 * @property FetchStatus $fetch_status
 * @property string|null $fetch_error
 * @property int $fetch_attempts
 * @property Carbon|null $fetched_at
 * @property Carbon|null $added_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Tag> $tags
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /**
     * The disk article images are stored on. It is the private local disk:
     * images are only ever served through the authenticated image route.
     */
    public const DISK = 'local';

    /**
     * Sort orders the lists offer, keyed by their query-string value.
     */
    public const SORTS = [
        'newest' => 'Newest',
        'oldest' => 'Oldest',
        'longest' => 'Longest',
    ];

    protected $fillable = [
        'url',
        'normalized_url',
        'resolved_url',
        'title',
        'author',
        'site_name',
        'excerpt',
        'lead_image_url',
        'lead_image_path',
        'content_html',
        'content_text',
        'word_count',
        'reading_time',
        'language',
        'published_at',
        'status',
        'archived_at',
        'is_favourite',
        'favourited_at',
        'read_progress',
        'last_read_at',
        'source',
        'fetch_status',
        'fetch_error',
        'fetch_attempts',
        'fetched_at',
        'added_at',
    ];

    protected $attributes = [
        'status' => 'unread',
        'source' => 'web',
        'fetch_status' => 'pending',
        'is_favourite' => false,
        'read_progress' => 0,
        'word_count' => 0,
        'reading_time' => 0,
        'fetch_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'source' => ItemSource::class,
            'fetch_status' => FetchStatus::class,
            'is_favourite' => 'boolean',
            'read_progress' => 'float',
            'word_count' => 'integer',
            'reading_time' => 'integer',
            'fetch_attempts' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'favourited_at' => 'datetime',
            'last_read_at' => 'datetime',
            'fetched_at' => 'datetime',
            'added_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Item $item): void {
            $item->added_at ??= now();
        });

        // Stored images go with the item. Bulk deletes therefore load the
        // models and delete them one by one rather than via the query
        // builder, which would skip this.
        static::deleted(function (Item $item): void {
            Storage::disk(self::DISK)->deleteDirectory($item->storageDirectory());
        });
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * Where this item's downloaded images live on the private disk.
     */
    public function storageDirectory(): string
    {
        return 'items/'.$this->id;
    }

    /**
     * The title to show: the extracted or given title, else the host.
     */
    public function displayTitle(): string
    {
        $title = trim((string) $this->title);

        return $title !== '' ? $title : ($this->host() ?: $this->url);
    }

    /**
     * The host shown under the title, without a leading "www.".
     */
    public function host(): string
    {
        $host = (string) parse_url($this->resolved_url ?: $this->url, PHP_URL_HOST);

        return (string) preg_replace('/^www\./i', '', $host);
    }

    /**
     * The link to the original page.
     */
    public function originalUrl(): string
    {
        return $this->resolved_url ?: $this->url;
    }

    public function isArchived(): bool
    {
        return $this->status === ItemStatus::Archived;
    }

    public function hasArticle(): bool
    {
        return $this->fetch_status === FetchStatus::Fetched && trim((string) $this->content_html) !== '';
    }

    public function archive(): void
    {
        $this->forceFill([
            'status' => ItemStatus::Archived,
            'archived_at' => now(),
        ])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill([
            'status' => ItemStatus::Unread,
            'archived_at' => null,
        ])->save();
    }

    public function setFavourite(bool $favourite): void
    {
        $this->forceFill([
            'is_favourite' => $favourite,
            'favourited_at' => $favourite ? now() : null,
        ])->save();
    }

    /**
     * Add tags by name, creating any that don't exist yet. Existing tags on
     * the item are kept.
     *
     * @param  iterable<string>  $names
     */
    public function attachTagNames(iterable $names): void
    {
        $ids = Tag::idsForNames($names);

        if ($ids !== []) {
            $this->tags()->syncWithoutDetaching($ids);
        }
    }

    /**
     * Replace the item's tags with exactly these names.
     *
     * @param  iterable<string>  $names
     */
    public function syncTagNames(iterable $names): void
    {
        $this->tags()->sync(Tag::idsForNames($names));
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->where('status', ItemStatus::Unread->value);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeArchived(Builder $query): void
    {
        $query->where('status', ItemStatus::Archived->value);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeFavourites(Builder $query): void
    {
        $query->where('is_favourite', true);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeTagged(Builder $query, Tag $tag): void
    {
        $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tag->getKey()));
    }

    /**
     * Restrict to items matching a full-text search, ranked by relevance
     * (title hits count most, then the site name, then the body).
     *
     * @param  Builder<Item>  $query
     */
    public function scopeSearch(Builder $query, string $term, bool $orderByRelevance = true): void
    {
        $match = self::ftsQuery($term);

        if ($match === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $matches = DB::table('items_fts')
            ->selectRaw('rowid AS fts_rowid, bm25(items_fts, 10.0, 5.0, 1.0) AS fts_rank')
            ->whereRaw('items_fts MATCH ?', [$match]);

        $query->joinSub($matches, 'fts', 'fts.fts_rowid', '=', 'items.id')
            ->select('items.*');

        if ($orderByRelevance) {
            $query->orderBy('fts.fts_rank');
        }
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeSorted(Builder $query, ?string $sort): void
    {
        if ($sort === 'oldest') {
            $query->orderBy('items.added_at')->orderBy('items.id');
        } elseif ($sort === 'longest') {
            $query->orderByDesc('items.word_count')->orderByDesc('items.id');
        } else {
            $query->orderByDesc('items.added_at')->orderByDesc('items.id');
        }
    }

    /**
     * Turn free text into a safe FTS5 query: every word becomes a quoted
     * prefix term, so FTS operators and stray quotes in the input can never
     * produce a syntax error. Words are ANDed together.
     */
    public static function ftsQuery(string $term): ?string
    {
        $words = preg_split('/\s+/u', trim(str_replace('"', ' ', $term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = array_map(
            static fn (string $word): string => '"'.$word.'"*',
            array_slice($words, 0, 12)
        );

        return $terms === [] ? null : implode(' ', $terms);
    }
}
