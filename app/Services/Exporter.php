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
     * Pocket's CSV. Lossless by default, so it imports back exactly. With
     * $forSpreadsheets, any cell a spreadsheet would run as a formula gets
     * a leading apostrophe: titles and tags come from other people's web
     * pages, and "=HYPERLINK(...)" in a title is someone else's formula.
     *
     * @param  resource  $handle
     */
    public function writeCsv($handle, bool $forSpreadsheets = false): void
    {
        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($this->items(withBodies: false) as $item) {
            $row = [
                $item->title ?? '',
                $item->url,
                (string) ($item->added_at?->getTimestamp() ?? ''),
                $item->tags->pluck('name')->implode('|'),
                $item->status->pocketName(),
            ];

            fputcsv($handle, $forSpreadsheets ? array_map(self::spreadsheetSafe(...), $row) : $row, ',', '"', '');
        }
    }

    /**
     * Neutralise a cell a spreadsheet would treat as a formula: one starting
     * with = + - or @, including after leading whitespace or control
     * characters, or starting with a tab or carriage return.
     */
    public static function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]|^[\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Written one item at a time, from small batches, so a large library
     * never has to fit in memory.
     *
     * @param  resource  $handle
     */
    public function writeJson($handle): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        fwrite($handle, '{"format":"docket","version":1,"exported_at":'.json_encode(now()->toIso8601String()).',"items":[');

        $first = true;

        foreach ($this->items(withBodies: true) as $item) {
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
     * Every item in ID order, fetched in small keyset batches (stable even
     * if items change mid-export). The CSV needs no article bodies; the
     * JSON does, so its batches are smaller.
     *
     * @return LazyCollection<int, Item>
     */
    protected function items(bool $withBodies): LazyCollection
    {
        return Item::query()
            ->when(! $withBodies, fn ($query) => $query->withoutBodies())
            ->with('tags')
            ->lazyById($withBodies ? 25 : 500, 'items.id', 'id');
    }
}
