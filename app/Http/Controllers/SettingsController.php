<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SettingsController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * Show the settings form: editable knobs above, read-only info below.
     */
    public function edit(): View
    {
        return view('settings.edit', [
            'fields' => Settings::fields(),
            'settings' => $this->settings,
            // Read-only values displayed for context (not editable here).
            'info' => [
                'Base currency' => config('docket.base_currency'),
                'Currency symbol' => config('docket.currency_symbol'),
                'Python binary' => config('docket.export.python_bin'),
                'Export script' => config('docket.export.script'),
                'Environment' => config('app.env'),
                'App URL' => config('app.url'),
                'Debug mode' => config('app.debug') ? 'On — should be off in production' : 'Off',
                'Database' => config('database.default'),
                'Queue driver' => config('queue.default'),
                'Filesystem disk' => config('filesystems.default'),
                'Mail driver' => config('mail.default'),
                'Receipt OCR' => config('services.google_vision.credentials')
                    ? 'Google Vision (falls back to Tesseract on error)'
                    : 'Tesseract only (Google Vision not configured)',
                'Tesseract OCR' => $this->tesseractStatus(),
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
     * Whether the Tesseract fallback (config('docket.ocr.tesseract_binary'))
     * actually resolves on this host, plus which language packs it has —
     * OCR on a non-English receipt silently produces garbage without the
     * matching pack installed. When it's missing, show the install command
     * for the host we're actually running on.
     */
    private function tesseractStatus(): string
    {
        $resolved = $this->resolveTesseractBinary(config('docket.ocr.tesseract_binary'));

        if ($resolved === null) {
            return PHP_OS_FAMILY === 'Darwin'
                ? 'Not found — install: brew install tesseract tesseract-lang (the -lang formula adds non-English languages, e.g. Italian)'
                : 'Not found — install: sudo apt install tesseract-ocr tesseract-ocr-eng (add tesseract-ocr-<lang> per language, e.g. tesseract-ocr-ita for Italian)';
        }

        return "Available ({$resolved}) — languages: {$this->tesseractLanguages($resolved)}";
    }

    /**
     * ExecutableFinder only searches $PATH — but a GUI-launched or
     * IDE-managed PHP process on macOS often doesn't inherit the
     * interactive shell's PATH, and that's specifically where Homebrew
     * adds its own bin directory (via .zprofile, which such processes
     * never source). So if the PATH search comes up empty and a bare
     * binary name was given (not an explicit path), also check Homebrew's
     * and the system's well-known install locations directly.
     */
    private function resolveTesseractBinary(string $binary): ?string
    {
        if (($found = (new ExecutableFinder())->find($binary)) !== null) {
            return $found;
        }

        if (str_contains($binary, '/')) {
            return null; // An explicit path was given and it didn't resolve.
        }

        foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin'] as $dir) {
            $candidate = "{$dir}/{$binary}";
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * List the installed language packs. `--list-langs` prints them on
     * stdout, except for the leading "List of available languages..." line.
     * Takes the resolved absolute path (not the bare binary name) so it
     * still runs under the same limited-PATH processes resolveTesseractBinary()
     * works around above.
     */
    private function tesseractLanguages(string $resolvedBinary): string
    {
        $process = new Process([$resolvedBinary, '--list-langs']);
        $process->run();

        $languages = collect(explode("\n", $process->getOutput()))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '' && ! str_starts_with($line, 'List of'));

        return $languages->isNotEmpty() ? $languages->implode(', ') : 'unknown';
    }
}
