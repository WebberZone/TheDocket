<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed overrides for the tunable knobs in config/docket.php.
 *
 * The config file remains the source of truth for app-policy defaults and
 * the values that genuinely differ per host (env-derived). Anything listed
 * in self::FIELDS() can additionally be overridden at runtime through the
 * Settings page; the override lives in the `settings` table.
 *
 * `get()` returns the DB override if one exists, otherwise falls back to
 * `config("docket.$key")`. The merged map is cached forever and invalidated
 * on every write, so callers pay one DB query per request at most.
 */
class Settings
{
    public const CACHE_KEY = 'docket.settings.all';

    /**
     * Extensions the receipts.mimes setting may contain. Receipts are
     * streamed back inline from an authed route, so active-content types
     * (svg, html, xml...) must never become accepted upload types — an
     * SVG "receipt" from a third party would run its scripts on this
     * origin. Static image formats and PDF only.
     */
    public const SAFE_RECEIPT_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif',
        'gif', 'tif', 'tiff', 'bmp', 'avif', 'pdf',
    ];

    /**
     * The editable fields, keyed by their dotted config path.
     *
     * Each entry:
     *  - label:  heading on the settings form
     *  - help:   explanatory line under the heading
     *  - type:   how the raw form input is cast before storage
     *      - string   -> trimmed string
     *      - ext_list -> comma-joined lowercased tokens (receipt extensions;
     *                    a stray space would silently break the mimes: rule)
     *      - int      -> integer
     *      - list     -> array of uppercased, trimmed tokens (currency codes)
     *      - list_int -> array of integers (OCR backoff seconds)
     *  - rules:  Laravel validation rules for the form input
     */
    public static function fields(): array
    {
        return [
            'currencies' => [
                'label' => 'Currencies',
                'help' => 'Comma-separated 3-letter codes offered in the currency dropdowns. The first should be the base currency.',
                'type' => 'list',
                'rules' => ['required', 'string', 'max:255'],
            ],
            'receipts.mimes' => [
                'label' => 'Allowed receipt file types',
                'help' => 'Comma-separated extensions accepted for receipt uploads and capture. Allowed: '.implode(', ', self::SAFE_RECEIPT_EXTENSIONS).'.',
                'type' => 'ext_list',
                'rules' => [
                    'required', 'string', 'max:255',
                    static function (string $attribute, mixed $value, \Closure $fail): void {
                        $extensions = array_filter(array_map(
                            static fn ($v) => strtolower(trim((string) $v)),
                            explode(',', (string) $value)
                        ));

                        foreach ($extensions as $extension) {
                            if (! in_array($extension, self::SAFE_RECEIPT_EXTENSIONS, true)) {
                                $fail("The file type \"{$extension}\" is not allowed for receipts. Allowed: ".implode(', ', self::SAFE_RECEIPT_EXTENSIONS).'.');
                            }
                        }
                    },
                ],
            ],
            'receipts.max_kb' => [
                'label' => 'Max receipt upload (KB)',
                'help' => 'Largest individual file accepted for a receipt, in kilobytes.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:1', 'max:102400'],
            ],
            'max_amount' => [
                'label' => 'Max transaction amount',
                'help' => 'Sanity cap on a single transaction line, in major units (e.g. pence/100). Not an accounting rule.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:1'],
            ],
            'ocr.tries' => [
                'label' => 'OCR retry attempts',
                'help' => 'How many times the queued Vision OCR job retries on transient failure.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
            ],
            'ocr.backoff' => [
                'label' => 'OCR backoff (seconds)',
                'help' => 'Comma-separated seconds to wait between each retry.',
                'type' => 'list_int',
                'rules' => ['required', 'string', 'max:255'],
            ],
        ];
    }

    /**
     * The HTML form field name for a settings key. PHP converts dots to
     * underscores in submitted form data, so a field named after the
     * dotted config path ("receipts.mimes") would arrive mangled and the
     * dotted validation rules would never see it — the form, rules and
     * controller all address the field by this underscore name instead.
     */
    public static function formKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * Read one setting: DB override if present, else the config default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? config("docket.$key");
    }

    /**
     * The full merged map of editable settings (DB override over config).
     */
    public function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            function () {
                $rows = Setting::query()->pluck('value', 'key')->all();

                $merged = [];
                foreach (array_keys(self::fields()) as $key) {
                    $merged[$key] = $rows[$key] ?? config("docket.$key");
                }

                return $merged;
            }
        );
    }

    /**
     * Persist one override (cast to the field's type) and bust the cache.
     */
    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $this->cast($key, $value)]
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Format a stored value for a form input (arrays become comma strings).
     */
    public function forInput(string $key): string
    {
        $value = $this->all()[$key] ?? null;

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) $value;
    }

    /**
     * Cast a raw form input to the storage type declared in self::fields().
     */
    protected function cast(string $key, mixed $value): mixed
    {
        $type = self::fields()[$key]['type'] ?? 'string';

        return match ($type) {
            'int' => (int) $value,
            'ext_list' => implode(',', array_values(
                array_filter(
                    array_map(
                        static fn ($v) => strtolower(trim((string) $v)),
                        explode(',', (string) $value)
                    )
                )
            )),
            'list' => array_values(
                array_filter(
                    array_map(
                        static fn ($v) => strtoupper(trim((string) $v)),
                        explode(',', (string) $value)
                    )
                )
            ),
            'list_int' => array_values(
                array_filter(
                    array_map(
                        static fn ($v) => (int) trim((string) $v),
                        explode(',', (string) $value)
                    ),
                    static fn ($v) => $v > 0
                )
            ),
            default => trim((string) $value),
        };
    }
}
