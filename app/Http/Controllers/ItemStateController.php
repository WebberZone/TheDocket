<?php

namespace App\Http\Controllers;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The one-tap actions on an item. Each answers JSON to fetch() calls from
 * the reader and redirects for plain form posts.
 */
class ItemStateController extends Controller
{
    public function archive(Request $request, Item $item): JsonResponse|RedirectResponse
    {
        $item->archive();

        // Archiving from the reader means "done with this one": go back to
        // the list rather than staying on the article.
        if ($request->input('then') === 'list') {
            return redirect()->route('items.index')->with('status', 'Archived.');
        }

        return $this->respond($request, $item, 'Archived.');
    }

    public function unarchive(Request $request, Item $item): JsonResponse|RedirectResponse
    {
        $item->unarchive();

        return $this->respond($request, $item, 'Moved back to unread.');
    }

    public function favourite(Request $request, Item $item): JsonResponse|RedirectResponse
    {
        $item->setFavourite(true);

        return $this->respond($request, $item, 'Added to favourites.');
    }

    public function unfavourite(Request $request, Item $item): JsonResponse|RedirectResponse
    {
        $item->setFavourite(false);

        return $this->respond($request, $item, 'Removed from favourites.');
    }

    public function retry(Request $request, Item $item): JsonResponse|RedirectResponse
    {
        $item->forceFill([
            'fetch_status' => FetchStatus::Pending,
            'fetch_error' => null,
            'fetch_attempts' => 0,
        ])->save();

        FetchItemContent::dispatch($item);

        return $this->respond($request, $item, 'Fetching the article again.');
    }

    /**
     * Reader scroll position, sent while reading and on leaving the page.
     */
    public function progress(Request $request, Item $item): Response
    {
        $data = $request->validate([
            'progress' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $item->forceFill([
            'read_progress' => round((float) $data['progress'], 4),
            'last_read_at' => now(),
        ])->saveQuietly();

        return response()->noContent();
    }

    protected function respond(Request $request, Item $item, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'status' => $item->status->value,
                'is_favourite' => $item->is_favourite,
                'fetch_status' => $item->fetch_status->value,
            ]);
        }

        return back()->with('status', $message);
    }
}
