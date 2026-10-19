<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\ImageLocaliser;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an article's locally stored images. They live on the private
 * disk, so only the logged-in owner can load them.
 */
class ItemImageController extends Controller
{
    public function __invoke(Item $item, string $filename): StreamedResponse
    {
        $path = $item->storageDirectory().'/'.$filename;
        $disk = Storage::disk(Item::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $filename, [
            'Content-Type' => ImageLocaliser::mimeFor($filename),
            // Filenames are content-addressed by source URL, so they never
            // change under the same name.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
