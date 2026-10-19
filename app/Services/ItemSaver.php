<?php

namespace App\Services;

use App\Enums\ItemSource;
use App\Enums\ItemStatus;
use App\Exceptions\InvalidUrlException;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The one way a link gets saved, shared by the paste form, bookmarklet,
 * share target and API. Pocket imports use PocketImporter instead, because
 * they must keep the original dates and status.
 *
 * De-duplication is on the normalised URL. Saving a link that is already
 * there works the way Pocket did: the item moves back to the top of the
 * unread list (keeping its tags, article and favourite) and any new tags
 * are added.
 */
class ItemSaver
{
    public function __construct(protected UrlNormaliser $normaliser) {}

    /**
     * @param  string|iterable<mixed>|null  $tags
     *
     * @throws InvalidUrlException
     */
    public function save(string $url, ?string $title = null, string|iterable|null $tags = [], ItemSource $source = ItemSource::Web): SaveResult
    {
        $url = $this->normaliser->clean($url);
        $normalised = $this->normaliser->normalise($url);
        $title = $this->cleanTitle($title);
        $tagNames = Tag::parseList($tags);

        $existing = Item::query()->where('normalized_url', $normalised)->first();

        if ($existing !== null) {
            return $this->resave($existing, $title, $tagNames);
        }

        try {
            $item = Item::query()->create([
                'url' => $url,
                'normalized_url' => $normalised,
                'title' => $title,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Saved twice at the same moment (a double-tapped share sheet).
            return $this->resave(
                Item::query()->where('normalized_url', $normalised)->firstOrFail(),
                $title,
                $tagNames
            );
        }

        $item->attachTagNames($tagNames);

        FetchItemContent::dispatch($item);

        return new SaveResult($item, true);
    }

    /**
     * @param  list<string>  $tagNames
     */
    protected function resave(Item $item, ?string $title, array $tagNames): SaveResult
    {
        $item->forceFill([
            'status' => ItemStatus::Unread,
            'archived_at' => null,
            'added_at' => now(),
        ]);

        if ($title !== null && trim((string) $item->title) === '') {
            $item->title = $title;
        }

        $item->save();
        $item->attachTagNames($tagNames);

        return new SaveResult($item, false);
    }

    protected function cleanTitle(?string $title): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));

        return $title === '' ? null : mb_substr($title, 0, 1000);
    }
}
