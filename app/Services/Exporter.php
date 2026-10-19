<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Writes the two export formats to a stream:
 *
 *  - CSV in the shape of Pocket's export (title, url, time_added, tags,
 *    status), so it goes back into Docket, Pocket-compatible tools, or a
 *    spreadsheet;
 *  - JSON with everything, including the extracted article text and HTML,
 *    for a complete copy of the library.
 */
class Exporter
{
    public const CSV_COLUMNS = ['title', 'url', 'time_added', 'tags', 'status'];

    /**
     * @param  resource  $handle
     */
    public function writeCsv($handle): void
    {
        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($this->items() as $item) {
            fputcsv($handle, [
                $item->title ?? '',
                $item->url,
                (string) ($item->added_at?->getTimestamp() ?? ''),
                $item->tags->pluck('name')->implode('|'),
                $item->status->pocketName(),
            ], ',', '"', '');
        }
    }

    /**
     * Streamed one item at a time so a large library never has to fit in
     * memory as a single array.
     *
     * @param  resource  $handle
     */
    public function writeJson($handle): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        fwrite($handle, '{"format":"docket","version":1,"exported_at":'.json_encode(now()->toIso8601String()).',"items":[');

        $first = true;

        foreach ($this->items() as $item) {
            fwrite($handle, ($first ? "\n" : ",\n").json_encode($this->itemToArray($item), $flags));
            $first = false;
        }

        fwrite($handle, "\n]}\n");
    }

    /**
     * @return array<string, mixed>
     */
    public function itemToArray(Item $item): array
    {
        $date = fn (?Carbon $value): ?string => $value?->toIso8601String();

        return [
            'id' => $item->id,
            'url' => $item->url,
            'normalized_url' => $item->normalized_url,
            'resolved_url' => $item->resolved_url,
            'title' => $item->title,
            'author' => $item->author,
            'site_name' => $item->site_name,
            'excerpt' => $item->excerpt,
            'lead_image_url' => $item->lead_image_url,
            'language' => $item->language,
            'published_at' => $date($item->published_at),
            'status' => $item->status->value,
            'is_favourite' => $item->is_favourite,
            'tags' => $item->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'word_count' => $item->word_count,
            'reading_time' => $item->reading_time,
            'read_progress' => $item->read_progress,
            'source' => $item->source->value,
            'fetch_status' => $item->fetch_status->value,
            'fetch_error' => $item->fetch_error,
            'added_at' => $date($item->added_at),
            'archived_at' => $date($item->archived_at),
            'favourited_at' => $date($item->favourited_at),
            'last_read_at' => $date($item->last_read_at),
            'fetched_at' => $date($item->fetched_at),
            'content_text' => $item->content_text,
            'content_html' => $item->content_html,
        ];
    }

    /**
     * Oldest first, so an export re-imported in order keeps its sequence.
     *
     * @return LazyCollection<int, Item>
     */
    protected function items(): LazyCollection
    {
        return Item::query()
            ->with('tags')
            ->orderBy('added_at')
            ->orderBy('id')
            ->lazy(200);
    }
}
