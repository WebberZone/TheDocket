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
     * The editable fields, keyed by their dotted config path.
     *
     * Each entry:
     *  - label:  heading on the settings form
     *  - help:   explanatory line under the heading
     *  - type:   how the raw form input is cast before storage
     *      - string   -> trimmed string
     *      - int      -> integer
     *      - list_int -> array of positive integers (retry backoff seconds)
     *  - rules:  Laravel validation rules for the form input
     *
     * @return array<string, array{label: string, help: string, type: string, rules: list<mixed>}>
     */
    public static function fields(): array
    {
        return [
            'words_per_minute' => [
                'label' => 'Reading speed (words per minute)',
                'help' => 'Used to estimate reading time. Applies to articles fetched after the change.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:50', 'max:2000'],
            ],
            'per_page' => [
                'label' => 'Items per page',
                'help' => 'How many items the lists show before paginating.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:5', 'max:200'],
            ],
            'fetch.timeout' => [
                'label' => 'Fetch timeout (seconds)',
                'help' => 'How long to wait for a page to download before giving up.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:2', 'max:120'],
            ],
            'fetch.max_kb' => [
                'label' => 'Largest page fetched (KB)',
                'help' => 'Downloads larger than this are abandoned and the item is marked as failed.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:64', 'max:51200'],
            ],
            'fetch.tries' => [
                'label' => 'Fetch attempts',
                'help' => 'How many times the queued fetch job tries a page before recording a failure.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
            ],
            'fetch.backoff' => [
                'label' => 'Fetch backoff (seconds)',
                'help' => 'Comma-separated seconds to wait between each attempt.',
                'type' => 'list_int',
                'rules' => ['required', 'string', 'max:255', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            ],
            'images.max_per_item' => [
                'label' => 'Images saved per article',
                'help' => 'Images downloaded (or tried) per article, lead image included, so articles survive link rot. Set to 0 to keep every image remote.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:0', 'max:500'],
            ],
            'import.spacing_seconds' => [
                'label' => 'Import spacing (seconds)',
                'help' => 'Gap between extraction jobs queued by a Pocket import, so a large import fetches gently.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:0', 'max:3600'],
            ],
        ];
    }

    /**
     * The HTML form field name for a settings key. PHP converts dots to
     * underscores in submitted form data, so a field named after the
     * dotted config path ("fetch.timeout") would arrive mangled and the
     * dotted validation rules would never see it. The form, rules and
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
     *
     * @return array<string, mixed>
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
