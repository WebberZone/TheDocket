<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\ImageLocaliser;
use Illuminate\Http\JsonResponse;

/**
 * The list the service worker (public/sw.js) keeps on the device for
 * offline reading: the newest unread articles that have a readable copy,
 * each with the reader page's address, a version that changes whenever the
 * page would, and the stored images the page uses.
 *
 * The worker fetches each reader page and image itself, so this only says
 * what to keep. Anything not in the list is dropped from the device on the
 * next sync, which is how archiving or deleting an article (or turning the
 * setting down to 0) removes its offline copy.
 */
class OfflineController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $limit = (int) settings('offline.articles');

        $items = $limit < 1 ? collect() : Item::query()
            ->withoutBodies()
            ->addSelect('items.content_html')
            ->unread()
            ->whereNotNull('items.content_html')
            ->where('items.content_html', '!=', '')
            ->sorted('newest')
            ->limit($limit)
            ->get();

        return response()->json(
            [
                'items' => $items->map(fn (Item $item): array => [
                    'url' => route('items.show', $item, false),
                    'version' => (string) $item->updated_at?->getTimestamp(),
                    'title' => $item->displayTitle(),
                    'site' => $item->site_name ?: $item->host(),
                    'minutes' => (int) $item->reading_time,
                    'images' => $this->images($item),
                ])->values()->all(),
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    /**
     * The stored images the article's HTML points at. Remote images are
     * left out: they come from other sites and can't be kept reliably.
     *
     * @return list<string>
     */
    protected function images(Item $item): array
    {
        // The route's own prefix, so this follows any change to the URL.
        $prefix = substr(route('items.image', ['item' => $item, 'filename' => 'x'], false), 0, -1);

        preg_match_all(
            '#src="('.preg_quote($prefix, '#').ImageLocaliser::FILENAME_PATTERN.')"#',
            (string) $item->content_html,
            $matches
        );

        return array_values(array_unique($matches[1]));
    }
}
