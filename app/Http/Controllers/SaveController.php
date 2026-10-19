<?php

namespace App\Http\Controllers;

use App\Enums\ItemSource;
use App\Exceptions\InvalidUrlException;
use App\Services\ItemSaver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two "save from somewhere else" entry points. Both are GETs because
 * that is how they arrive: the bookmarklet opens a small window on
 * /save?url=..., and Android's share sheet opens /share?url=...&text=...
 * (see share_target in public/manifest.webmanifest). If the session has
 * expired the auth middleware sends the visitor to log in and back here,
 * and the save goes through afterwards.
 *
 * A third-party page could link someone to these URLs too. The worst that
 * does is add a link to their own reading list, and the fetch it triggers
 * goes through the same SSRF guard as everything else.
 */
class SaveController extends Controller
{
    public function bookmarklet(Request $request, ItemSaver $saver): Response
    {
        $url = trim($request->string('url')->toString());

        try {
            $result = $saver->save($url, $request->string('title')->toString(), null, ItemSource::Bookmarklet);
        } catch (InvalidUrlException $e) {
            return response()->view('save.done', ['result' => null, 'error' => $e->getMessage()], 422);
        }

        return response()->view('save.done', ['result' => $result, 'error' => null]);
    }

    public function share(Request $request, ItemSaver $saver): RedirectResponse
    {
        $url = $this->sharedUrl(
            $request->string('url')->toString(),
            $request->string('text')->toString(),
            $request->string('title')->toString(),
        );

        if ($url === null) {
            return redirect()->route('items.index')->with('error', 'Nothing to save: the share did not include a link.');
        }

        try {
            $result = $saver->save($url, $request->string('title')->toString(), null, ItemSource::Share);
        } catch (InvalidUrlException $e) {
            return redirect()->route('items.index')->with('error', $e->getMessage());
        }

        return redirect()->route('items.index')->with('status', $result->message());
    }

    /**
     * Android apps disagree on where the link goes: some fill "url", most
     * put it in "text" (often after a headline), a few in "title".
     */
    protected function sharedUrl(string ...$fields): ?string
    {
        foreach ($fields as $field) {
            if (preg_match('#https?://[^\s<>"]+#i', $field, $match)) {
                return rtrim($match[0], '.,;:!?)\'"');
            }
        }

        return null;
    }
}
