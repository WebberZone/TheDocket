<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Personal API tokens (Laravel Sanctum) for POST /api/items. The plain
 * token is shown once, straight after creation; only its hash is stored.
 */
class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $token = $request->user()->createToken($data['name']);

        return redirect()->route('settings.edit')
            ->with('status', "Token \"{$data['name']}\" created. Copy it now: it will not be shown again.")
            ->with('plain_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return redirect()->route('settings.edit')->with('status', 'Token revoked.');
    }
}
