<?php

namespace App\Services;

use App\Exceptions\FetchFailedException;
use App\Exceptions\ResponseTooLargeException;
use App\Models\Item;
use App\Services\Fetching\SafeHttpClient;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Masterminds\HTML5;
use Throwable;

/**
 * Downloads an article's images to private storage and points their src
 * at the app's own image route, so a saved article keeps its pictures
 * after the original site changes or disappears.
 *
 * Images go through SafeHttpClient like any other fetch. Only raster
 * formats are kept (SVG can carry scripts and would be served from this
 * origin); anything that fails, is too big, or is past the article's
 * budget keeps its remote src. The budget (ImageDownloads) is shared by the
 * inline images and the lead image of one article.
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
     * A download budget for one article: use it for the inline images and
     * then the lead image, so both share one set of limits and the lead
     * image reuses an inline copy.
     */
    public function forItem(Item $item): ImageDownloads
    {
        return new ImageDownloads($this, $item);
    }

    /**
     * Rewrite every <img> in already-sanitised article HTML, with a fresh
     * budget. Use forItem() when the lead image should share it.
     */
    public function localise(Item $item, string $html): string
    {
        return $this->forItem($item)->localise($html);
    }

    /**
     * Rewrite every <img> src that $download stores to the local copy.
     *
     * @param  callable(string): ?string  $download  URL in, stored filename (or null) out
     */
    public function rewrite(Item $item, string $html, callable $download): string
    {
        if (! str_contains($html, '<img')) {
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

        foreach (iterator_to_array($root->getElementsByTagName('img')) as $img) {
            $src = $img->getAttribute('src');

            if (! preg_match('#^https?://#i', $src)) {
                continue;
            }

            $filename = $download($src);

            if ($filename !== null) {
                $img->setAttribute('src', $this->url($item, $filename));
            }
        }

        return $html5->saveHTML($root->childNodes);
    }

    /**
     * Download one image into the item's directory.
     *
     * @return array{0: string|null, 1: int} the stored filename (null if
     *                                       not stored) and bytes downloaded
     */
    public function store(Item $item, string $url, int $maxBytes, int $timeout): array
    {
        try {
            $response = $this->http->get($url, $maxBytes, 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1', $timeout);
        } catch (FetchFailedException $e) {
            Log::debug('Image not stored', ['item' => $item->id, 'url' => $url, 'reason' => $e->getMessage()]);

            // An abandoned oversized download spent (up to) its cap.
            return [null, $e instanceof ResponseTooLargeException ? $e->limit : 0];
        }

        $bytes = strlen($response->body);

        // Trust the bytes, not the header: a server can label anything
        // image/png.
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($response->body);
        $extension = self::TYPES[$sniffed] ?? null;

        if ($extension === null) {
            return [null, $bytes];
        }

        $filename = sha1($url).'.'.$extension;
        $path = $item->storageDirectory().'/'.$filename;

        // A failed write (full disk, permissions) must leave the working
        // remote src in place, not point the article at a missing file.
        try {
            $written = Storage::disk(Item::DISK)->put($path, $response->body);
        } catch (Throwable $e) {
            report($e);
            $written = false;
        }

        if ($written === false) {
            Log::warning('Could not store an article image; keeping its remote address.', ['item' => $item->id, 'path' => $path]);

            return [null, $bytes];
        }

        return [$filename, $bytes];
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
