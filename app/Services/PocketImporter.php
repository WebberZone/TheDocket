<?php

namespace App\Services;

use App\Enums\ItemSource;
use App\Enums\ItemStatus;
use App\Exceptions\InvalidUrlException;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports Pocket's export CSV (title, url, time_added, tags, status, plus
 * any extra columns such as "cursor", matched by header name).
 *
 * The original added date, tags and unread/archive status are kept.
 * Re-running the same file is safe: links already in Docket are matched on
 * their normalised URL and only gain any missing tags; nothing is
 * duplicated, re-dated or re-queued. New items are queued for extraction
 * spaced apart (config docket.import.spacing_seconds) so a few thousand
 * links are fetched gently.
 */
class PocketImporter
{
    /**
     * Rows written per transaction.
     */
    protected const CHUNK = 250;

    public function __construct(protected UrlNormaliser $normaliser) {}

    /**
     * @throws RuntimeException when the file is unreadable or has no url column
     */
    public function import(string $path): ImportResult
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new RuntimeException('Could not read the import file.');
        }

        try {
            return $this->importFrom($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    protected function importFrom($handle): ImportResult
    {
        $columns = $this->columns(fgetcsv($handle, null, ',', '"', '') ?: []);

        if (! isset($columns['url'])) {
            throw new RuntimeException('That file has no "url" column. Upload the CSV from Pocket\'s export (title, url, time_added, tags, status).');
        }

        $result = new ImportResult();
        $spacing = max(0, (int) settings('import.spacing_seconds'));
        $seen = [];
        $chunk = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $chunk[] = $row;

            if (count($chunk) >= self::CHUNK) {
                $this->importChunk($chunk, $columns, $result, $seen, $spacing);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->importChunk($chunk, $columns, $result, $seen, $spacing);
        }

        return $result;
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     * @param  array<string, int>  $columns
     * @param  array<string, int>  $seen
     */
    protected function importChunk(array $rows, array $columns, ImportResult $result, array &$seen, int $spacing): void
    {
        /** @var list<Item> $created */
        $created = DB::transaction(function () use ($rows, $columns, $result, &$seen): array {
            $created = [];

            foreach ($rows as $row) {
                $value = fn (string $column): string => trim((string) ($row[$columns[$column] ?? -1] ?? ''));

                try {
                    $url = $this->normaliser->clean($value('url'));
                    $normalised = $this->normaliser->normalise($url);
                } catch (InvalidUrlException) {
                    $result->invalid++;

                    continue;
                }

                $tags = Tag::parseList(explode('|', $value('tags')));

                // Listed twice in the same file, or already in Docket: only
                // the tags are merged, so a re-run converges on the same state.
                $existing = isset($seen[$normalised])
                    ? Item::query()->find($seen[$normalised])
                    : Item::query()->where('normalized_url', $normalised)->first();

                if ($existing !== null) {
                    $existing->attachTagNames($tags);
                    $seen[$normalised] = $existing->id;
                    $result->existing++;

                    continue;
                }

                $added = $this->timestamp($value('time_added'));
                $status = ItemStatus::fromPocket($value('status'));
                $title = $value('title');

                $item = Item::query()->create([
                    'url' => $url,
                    'normalized_url' => $normalised,
                    // Pocket uses the URL as the title for links it never
                    // managed to parse; leave those for the extractor.
                    'title' => $title !== '' && $title !== $url ? mb_substr($title, 0, 1000) : null,
                    'source' => ItemSource::Pocket,
                    'status' => $status,
                    'archived_at' => $status === ItemStatus::Archived ? $added : null,
                    'added_at' => $added,
                ]);

                $item->attachTagNames($tags);
                $seen[$normalised] = $item->id;
                $created[] = $item;
                $result->created++;
            }

            return $created;
        });

        // Queued after the commit, so a sync queue never fetches inside
        // the transaction.
        foreach ($created as $item) {
            FetchItemContent::dispatch($item)->delay(now()->addSeconds($result->queued * $spacing));
            $result->queued++;
        }
    }

    /**
     * Map header names to column positions, ignoring case, whitespace and
     * a UTF-8 byte-order mark.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    protected function columns(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $name) {
            $name = strtolower(trim(str_replace("\u{FEFF}", '', (string) $name)));

            if ($name !== '') {
                $columns[$name] ??= $index;
            }
        }

        return $columns;
    }

    protected function timestamp(string $value): Carbon
    {
        if (ctype_digit($value) && (int) $value > 0) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return now();
    }
}
