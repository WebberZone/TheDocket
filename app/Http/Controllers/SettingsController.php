<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * Settings: the editable knobs, the ways to save links (bookmarklet,
     * share target, API tokens) and read-only info.
     */
    public function edit(Request $request): View
    {
        return view('settings.edit', [
            'fields' => Settings::fields(),
            'settings' => $this->settings,
            'bookmarklet' => self::bookmarklet(route('save.bookmarklet')),
            'tokens' => $request->user()->tokens()->latest()->get(),
            'plainToken' => $request->session()->get('plain_token'),
            'apiUrl' => route('api.items.store'),
            // Read-only values displayed for context (not editable here).
            'info' => [
                'Environment' => config('app.env'),
                'App URL' => config('app.url'),
                'Debug mode' => config('app.debug') ? 'On — should be off in production' : 'Off',
                'Database' => config('database.default'),
                'Queue driver' => config('queue.default').(config('queue.default') === 'sync' ? ' — pages are fetched during the save request' : ''),
                'Filesystem disk' => config('filesystems.default'),
                'Mail driver' => config('mail.default'),
                'Fetch User-Agent' => config('docket.fetch.user_agent'),
                'Off-site backups' => config('filesystems.disks.offsite.bucket')
                    ? config('filesystems.disks.offsite.bucket').' @ '.config('filesystems.disks.offsite.endpoint')
                    : 'Not configured',
                'Backup failure notifications' => config('backup.notifications.mail.to'),
            ],
        ]);
    }

    /**
     * Persist the editable overrides.
     */
    public function update(SettingsRequest $request): RedirectResponse
    {
        foreach (array_keys(Settings::fields()) as $key) {
            $this->settings->set($key, $request->input(Settings::formKey($key)));
        }

        return Redirect::route('settings.edit')->with('status', 'Settings updated successfully.');
    }

    /**
     * The bookmarklet: opens a small window on /save with the current
     * page's URL and title, or navigates there if a popup blocker
     * interferes.
     */
    public static function bookmarklet(string $saveUrl): string
    {
        $js = sprintf(
            "(function(){var u=%s+'?url='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title);"
            ."if(!window.open(u,'docket','width=440,height=320,menubar=no,toolbar=no')){location.href=u;}})();",
            json_encode($saveUrl, JSON_UNESCAPED_SLASHES)
        );

        return 'javascript:'.$js;
    }
}
