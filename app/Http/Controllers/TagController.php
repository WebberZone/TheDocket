<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(): View
    {
        return view('tags.index', [
            'tags' => Tag::query()->withCount('items')->orderBy('name')->get(),
        ]);
    }

    /**
     * Rename a tag. Renaming onto another tag's name merges the two.
     */
    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.Tag::MAX_LENGTH],
        ]);

        $name = Tag::cleanName($data['name']);

        if ($name === '') {
            return back()->withErrors(['name' => 'Enter a tag name.']);
        }

        $target = Tag::query()
            ->where('slug', Tag::slugFor($name))
            ->whereKeyNot($tag->id)
            ->first();

        if ($target === null) {
            $tag->update(['name' => $name, 'slug' => Tag::slugFor($name)]);

            return redirect()->route('tags.index')->with('status', "Renamed to \"{$name}\".");
        }

        DB::transaction(function () use ($tag, $target): void {
            $target->items()->syncWithoutDetaching($tag->items()->pluck('items.id')->all());
            $tag->delete();
        });

        return redirect()->route('tags.index')->with('status', "Merged into \"{$target->name}\".");
    }

    /**
     * Delete a tag. The items keep everything else.
     */
    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return redirect()->route('tags.index')->with('status', "Deleted the tag \"{$tag->name}\".");
    }
}
