<?php

namespace App\Http\Controllers;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bulk actions on the items ticked in a list.
 */
class BulkItemController extends Controller
{
    public const ACTIONS = [
        'archive' => 'Archive',
        'unarchive' => 'Move to unread',
        'favourite' => 'Favourite',
        'unfavourite' => 'Unfavourite',
        'tag' => 'Add tags',
        'retry' => 'Fetch again',
        'delete' => 'Delete',
    ];

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'tags' => ['nullable', 'string', 'max:1000', 'required_if:action,tag'],
        ], [
            'ids.required' => 'Tick at least one item first.',
            'tags.required_if' => 'Enter the tags to add.',
        ]);

        $tags = Tag::parseList($data['tags'] ?? null);
        $count = 0;

        // In batches, without the article bodies: a thousand ticked items
        // must not mean a thousand full articles in memory at once.
        Item::query()
            ->withoutBodies()
            ->whereIn('items.id', $data['ids'])
            ->chunkById(100, function ($items) use ($data, $tags, &$count): void {
                foreach ($items as $item) {
                    $count++;

                    switch ($data['action']) {
                        case 'archive':
                            $item->archive();
                            break;
                        case 'unarchive':
                            $item->unarchive();
                            break;
                        case 'favourite':
                            $item->setFavourite(true);
                            break;
                        case 'unfavourite':
                            $item->setFavourite(false);
                            break;
                        case 'tag':
                            $item->attachTagNames($tags);
                            break;
                        case 'retry':
                            $this->retry($item);
                            break;
                        case 'delete':
                            // One by one, so each item's stored images go too.
                            $item->delete();
                            break;
                    }
                }
            }, 'items.id', 'id');

        return back()->with('status', sprintf(
            '%s: %d %s.',
            self::ACTIONS[$data['action']],
            $count,
            $count === 1 ? 'item' : 'items'
        ));
    }

    protected function retry(Item $item): void
    {
        $item->forceFill([
            'fetch_status' => FetchStatus::Pending,
            'fetch_error' => null,
            'fetch_attempts' => 0,
        ])->save();

        FetchItemContent::dispatch($item);
    }
}
