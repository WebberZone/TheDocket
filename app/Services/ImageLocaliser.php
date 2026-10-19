<?php

namespace App\Services;

use App\Exceptions\FetchFailedException;
use App\Models\Item;
use App\Services\Fetching\SafeHttpClient;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Masterminds\HTML5;

/**
 * Downloads an article's images to private storage and points their src
 * at the app's own image route, so a saved article keeps its pictures
 * after the original site changes or disappears.
 *
 * Images go through SafeHttpClient like any other fetch. Only raster
 * formats are kept (SVG can carry scripts and would be served from this
 * origin); anything that fails, is too big, or is past the per-article cap
 * keeps its remote src.
 */
class ImageLocaliser
{
    /**
     * Content types stored, and the extension each is saved under.
     */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * Matches a stored image filename (and nothing else) for the route.
     */
    public const FILENAME_PATTERN = '[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif)';

    public function __construct(protected SafeHttpClient $http) {}

    /**
     * Rewrite every <img> in already-sanitised article HTML.
     */
    public function localise(Item $item, string $html): string
    {
        $limit = (int) settings('images.max_per_item');

        if (! config('docket.images.enabled') || $limit < 1 || ! str_contains($html, '<img')) {
            return $html;
        }

        // Parsed as a whole document (wrapped in a marker element) rather
        // than a fragment, so getElementsByTagName() can see the images.
        $html5 = new HTML5(['disable_html_ns' => true]);
        $dom = $html5->loadHTML('<!DOCTYPE html><html><body><div id="docket-article">'.$html.'</div></body></html>');
        $matches = (new DOMXPath($dom))->query('//*[@id="docket-article"]');
        $root = $matches === false ? null : $matches->item(0);

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $stored = [];

        foreach (iterator_to_array($root->getElementsByTagName('img')) as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $src = $img->getAttribute('src');

            if (! preg_match('#^https?://#i', $src)) {
                continue;
            }

            if (! array_key_exists($src, $stored)) {
                $stored[$src] = count(array_filter($stored)) < $limit
                    ? $this->store($item, $src)
                    : null;
            }

            if ($stored[$src] !== null) {
                $img->setAttribute('src', $this->url($item, $stored[$src]));
            }
        }

        return $html5->saveHTML($root->childNodes);
    }

    /**
     * Download one image into the item's directory.
     *
     * @return string|null the stored filename, or null if it was not stored
     */
    public function store(Item $item, string $url): ?string
    {
        try {
            $response = $this->http->get($url, (int) config('docket.images.max_kb') * 1024, 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1');
        } catch (FetchFailedException $e) {
            Log::debug('Image not stored', ['item' => $item->id, 'url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }

        // Trust the bytes, not the header: a server can label anything
        // image/png.
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($response->body);
        $extension = self::TYPES[$sniffed] ?? null;

        if ($extension === null) {
            return null;
        }

        $filename = sha1($url).'.'.$extension;

        Storage::disk(Item::DISK)->put($item->storageDirectory().'/'.$filename, $response->body);

        return $filename;
    }

    /**
     * The app-relative URL an image is served from. Relative, so stored
     * articles survive a change of domain or scheme.
     */
    public function url(Item $item, string $filename): string
    {
        return route('items.image', ['item' => $item, 'filename' => $filename], false);
    }

    /**
     * MIME type for a stored filename, for serving it back.
     */
    public static function mimeFor(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
