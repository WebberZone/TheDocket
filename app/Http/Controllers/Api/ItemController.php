<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemSource;
use App\Exceptions\InvalidUrlException;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Tag;
use App\Services\ItemSaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ItemController extends Controller
{
    /**
     * POST /api/items {url, title?, tags?}. tags may be an array or a
     * comma-separated string. 201 for a new item, 200 when the link was
     * already saved (it moves back to the top of the unread list).
     */
    public function store(Request $request, ItemSaver $saver): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable'],
            'tags.*' => ['string', 'max:100'],
        ]);

        $tags = $data['tags'] ?? null;

        if ($tags !== null && ! is_string($tags) && ! is_array($tags)) {
            throw ValidationException::withMessages(['tags' => 'Tags must be a list or a comma-separated string.']);
        }

        try {
            $result = $saver->save($data['url'], $data['title'] ?? null, $tags, ItemSource::Api);
        } catch (InvalidUrlException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return response()->json(
            ['data' => $this->present($result->item->load('tags')), 'created' => $result->created],
            $result->created ? 201 : 200
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Item $item): array
    {
        return [
            'id' => $item->id,
            'url' => $item->url,
            'title' => $item->title,
            'status' => $item->status->value,
            'is_favourite' => $item->is_favourite,
            'fetch_status' => $item->fetch_status->value,
            'tags' => $item->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'added_at' => $item->added_at?->toIso8601String(),
            'web_url' => route('items.show', $item),
        ];
    }
}
