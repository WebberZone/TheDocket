<?php

namespace App\Http\Controllers;

use App\Enums\ItemSource;
use App\Exceptions\InvalidUrlException;
use App\Models\Item;
use App\Models\Tag;
use App\Services\ItemSaver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    /**
     * Titles for each list view, keyed by the route's "view" default.
     */
    public const VIEWS = [
        'unread' => 'Unread',
        'archive' => 'Archive',
        'favourites' => 'Favourites',
        'search' => 'Search',
        'tag' => 'Tagged',
    ];

    /**
     * Every list: unread (the home page), archive, favourites, a tag, or
     * search results. ?sort= picks newest/oldest/longest; search results
     * default to relevance.
     */
    public function index(Request $request, string $view = 'unread'): View
    {
        return $this->list($request, $view);
    }

    /**
     * One tag's items. A separate action because route defaults and bound
     * parameters reach the controller positionally, so one signature can't
     * serve both.
     */
    public function tagged(Request $request, Tag $tag): View
    {
        return $this->list($request, 'tag', $tag);
    }

    protected function list(Request $request, string $view, ?Tag $tag = null): View
    {
        $view = array_key_exists($view, self::VIEWS) ? $view : 'unread';
        $q = trim($request->string('q')->toString());
        $sort = $request->query('sort');
        $sort = is_string($sort) && array_key_exists($sort, Item::SORTS) ? $sort : null;

        $query = Item::query()->with('tags');

        if ($view === 'archive') {
            $query->archived();
        } elseif ($view === 'favourites') {
            $query->favourites();
        } elseif ($view === 'tag' && $tag !== null) {
            $query->tagged($tag);
        } elseif ($view !== 'search') {
            $query->unread();
        }

        if ($q !== '') {
            $query->search($q, $sort === null);
        }

        if ($q === '' || $sort !== null) {
            $query->sorted($sort);
        }

        return view('items.index', [
            'items' => $query->paginate(max(1, (int) settings('per_page')))->withQueryString(),
            'view' => $view,
            'tag' => $tag,
            'q' => $q,
            'sort' => $sort ?? ($q !== '' ? 'relevance' : 'newest'),
            'heading' => $view === 'tag' && $tag !== null ? $tag->name : self::VIEWS[$view],
        ]);
    }

    /**
     * Save a pasted link.
     */
    public function store(Request $request, ItemSaver $saver): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $saver->save($data['url'], $data['title'] ?? null, $data['tags'] ?? null, ItemSource::Web);
        } catch (InvalidUrlException $e) {
            return back()->withErrors(['url' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $result->message());
    }

    /**
     * The reader.
     */
    public function show(Item $item): View
    {
        $item->load('tags');
        $item->forceFill(['last_read_at' => now()])->saveQuietly();

        return view('items.show', ['item' => $item]);
    }

    /**
     * Edit the title and tags.
     */
    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->has('title')) {
            $title = trim((string) ($data['title'] ?? ''));
            $item->title = $title !== '' ? $title : null;
            $item->save();
        }

        if ($request->has('tags')) {
            $item->syncTagNames(Tag::parseList($data['tags'] ?? null));
        }

        return back()->with('status', 'Saved your changes.');
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        $fromReader = url()->previous() === route('items.show', $item);

        $item->delete();

        return ($fromReader ? redirect()->route('items.index') : back())
            ->with('status', 'Deleted.');
    }
}
