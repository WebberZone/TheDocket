# AGENTS.md

Guidance for AI coding agents working in this repository. `CLAUDE.md` mirrors this file; keep them identical.

## What this is

Docket: a self-hosted, single-user read-later app (Pocket replacement). Laravel 13, PHP 8.3, SQLite (FTS5), Breeze (Blade), Tailwind 4, Alpine (CSP build), Vite. Default branch is `master`. See `README.md` for features, install and deployment.

## Commands

```bash
composer dev        # serve + queue:listen + pail + vite
composer test       # config:clear + artisan test
composer lint       # Pint (source of truth for style)
composer lint:test  # Pint --test, as CI runs it
composer stan       # PHPStan (Larastan), level 5
npm run build       # frontend; npm, not pnpm (package-lock.json is tracked)
```

CI (`.github/workflows/ci.yml`) runs Pint `--test`, PHPStan and the test suite on PHP 8.3. Run all three before committing. `phpcs.xml.dist` exists only for IDE diagnostics.

## Architecture

- **Saving**: every entry point (paste form, `GET /save` bookmarklet, `GET /share` PWA target, `POST /api/items` with a Sanctum token) goes through `App\Services\ItemSaver`. De-duplication is the unique `items.normalized_url` from `UrlNormaliser`. Pocket import uses `PocketImporter` instead.
- **Fetching**: `App\Jobs\FetchItemContent` → `Fetching\SafeHttpClient` → `Extraction\ArticleExtractor` (readability.php) → `HtmlSanitiser` → `ImageLocaliser`/`ImageDownloads`.
- **Settings**: defaults in `config/docket.php`; runtime overrides in the `settings` table via `App\Services\Settings` and the `settings()` helper. A new editable knob goes in `Settings::fields()`.
- **Installer**: `App\Services\Installer`, shared by `docket:install` and `/install`. Install routes run without the `web` middleware group (no DB/session yet). `docket:setup-code` prints the web installer's code; `docket:check-requirements` prints the requirements table. `tools/` holds the server-side scripts: `install.sh` (fresh Ubuntu/Debian), `update.sh` (git-checkout updater), `build-release.sh` (release zip + `dist` payload).
- **Scheduler-driven queue**: one cron line runs `schedule:run`, which beats the `SchedulerHeartbeat` file and — while `docket.queue.via_scheduler` is on (`DOCKET_QUEUE_VIA_SCHEDULER`, default true) — drains the queue with `queue:work --stop-when-empty`. The dashboard warns when the heartbeat goes stale.
- **Offline**: `public/sw.js` syncs from `OfflineController` (`/offline/articles`).
- **Search**: external-content FTS5 table `items_fts` kept in sync by triggers; always build MATCH strings with `Item::ftsQuery()`.

## Invariants — do not break

- **All outbound HTTP goes through `SafeHttpClient`.** Never call `Http::` directly for user-supplied URLs. Keep the forced `CurlHandler` (address pinning via `CURLOPT_RESOLVE` depends on it), manual redirect following and the byte caps.
- **Stored article HTML is sanitised before saving** and printed unescaped in the reader; the sanitiser allow-list is the security boundary.
- **Strict CSP** (`ContentSecurityPolicy` middleware): no inline scripts, `onclick`, or `style` attributes. Put behaviour in `resources/js/` (Alpine components or `data-*` hooks in `behaviours.js`); inline `<script>` must carry the Vite nonce.
- **Exactly one account.** No registration; installer and seeder refuse to create a second user.
- **List queries use `withoutBodies()`**; batch with `lazyById`/`chunkById`, never offset-based `lazy()` over a set that changes as it is processed.
- **Images**: only raster types sniffed by content are stored, on the private `local` disk under `items/{id}/`, served only via the authenticated image route. SVG stays remote.
- **Queue**: `retry_after` (360s) must stay above `FetchItemContent::$timeout` (300s) and the worker `--timeout` (including the scheduled `queue:work --timeout=300`).
- **The heartbeat lives in a file** (`storage/app/scheduler-heartbeat`), never the cache: clearing caches must not trigger the dead-cron warning.

## Tests

PHPUnit (not Pest), in-memory SQLite, sync queue. Feature tests must never touch the network: `Http::preventStrayRequests()` is on and DNS goes through `tests/Support/FakeHostResolver`. `RealTransportTest` exercises the real cURL transport against `tests/Fixtures/server/router.php`. Add a test with every behaviour change.

## Conventions

- British English in UI copy, docs and comments (favourites, sanitise, licence).
- No AI attribution in commits, PRs or comments.
