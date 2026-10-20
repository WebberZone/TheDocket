# Docket

A single-user read-later app you can self-host: a replacement for Pocket,
which Mozilla shut down in July 2025. Save a link from the browser, the
Android share sheet, an iOS Shortcut or the API; Docket fetches the
article, strips it down to the text and images, and keeps its own copy so
it survives link rot. Open source under the [MIT licence](LICENSE).

Laravel 13 · PHP 8.3 · SQLite · Breeze (Blade) · Tailwind · Alpine.

## What it does

- **Saving links** four ways: a bookmarklet, the PWA share target
  (Android "Share → Docket"), `POST /api/items` with a personal API token
  (for an iOS Shortcut, extensions, scripts), or pasting a URL into the
  app.
- **Extraction**: a queued job fetches the page and runs it through
  [readability.php](https://github.com/fivefilters/readability.php). It
  stores the title, author, site name, excerpt, lead image, clean HTML,
  plain text, word count, reading time, language and published date.
  Inline images are downloaded to private storage and the article's `src`
  attributes are rewritten to point at them. The HTML is sanitised against
  an allow-list before it is stored. Failures are recorded on the item
  with a readable reason, and you can retry.
- **Organising**: the unread list is the home page; then archive,
  favourites, tags (many-to-many, case-insensitive) and delete. Lists sort
  by newest, oldest or longest, and every list has bulk actions.
- **Search**: SQLite FTS5 over title, site name and article text, ranked
  with title hits first. Triggers keep the index in step with every write.
- **Reader**: serif or sans, adjustable size, light, sepia or dark;
  remembered per browser. Your scroll position is saved as you read and
  restored when you come back, on any device.
- **Pocket import**: upload Pocket's export CSV, or use
  `php artisan docket:import-pocket`. Dates, tags and unread/archive status
  are kept; extraction is queued at a gentle rate. Re-running it is safe.
- **Export**: CSV in Pocket's shape, and a full JSON export including the
  article text and HTML.

Saving a link that is already there works the way Pocket did: no
duplicate, the item moves back to the top of the unread list, and any new
tags are added. Links are de-duplicated on a normalised form of the URL
(lowercased host, no fragment, no `utm_*`/`fbclid`-style tracking
parameters, sorted query).

## Saving links

### Bookmarklet

**Settings → Bookmarklet**: drag the button to your bookmarks bar. On any
page, click it; a small window confirms the save and closes itself. If
your session has expired it asks you to log in first, then saves.

### Android: Share → Docket

Open Docket in Chrome on the phone and choose **Install app** (or **Add to
Home screen**). Docket then shows up in the share sheet of every app.
Sharing works out where the link is: most apps put it in the shared text,
some in the URL field. HTTPS is required for the install.

### iOS Shortcut

1. In Docket, **Settings → API tokens**: create a token (for example
   "iPhone") and copy it. It is shown once.
2. In the Shortcuts app, create a shortcut called **Save to Docket**. In
   its details, turn on **Show in Share Sheet** and set it to receive
   **URLs** (and Safari web pages).
3. Add **Get Contents of URL**:
   - URL: `https://your-domain/api/items`
   - Method: **POST**
   - Headers: `Authorization` = `Bearer <your token>`, and
     `Accept` = `application/json`
   - Request Body: **JSON**, with one field: `url` (Text) = **Shortcut
     Input**. Add `tags` (Text, comma-separated) if you want a fixed tag.
4. Optionally add **Show Notification** with "Saved to Docket".

Share any page or link to **Save to Docket**. If the shortcut reports a
401, the token is wrong or has been revoked.

### API

```sh
curl -X POST https://your-domain/api/items \
    -H "Authorization: Bearer $DOCKET_TOKEN" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -d '{"url": "https://example.com/article", "title": "Optional", "tags": ["later", "long reads"]}'
```

`tags` may be an array or a comma-separated string. The response is `201`
for a new item and `200` when the link was already saved, with the item
(including `web_url`, the reader link) in `data`. Invalid input returns
`422` with Laravel's usual `errors` object. The API is limited to 60
requests a minute. Tokens are Laravel Sanctum personal access tokens; only
a hash is stored, and revoking one on the Settings page stops it at once.

## Coming from Pocket

Pocket's export is a ZIP with one or more CSV files (`part_000000.csv`,
…) with the columns `title, url, time_added, tags, status`. Either upload
each CSV on **Import & export**, or:

```sh
php artisan docket:import-pocket /path/to/part_000000.csv
```

Each link keeps its saved date, its tags (Pocket separates them with `|`)
and its status (`archive` becomes archived). Links Pocket never parsed
come through with the URL as the title; Docket leaves those blank for the
extractor to fill in. Rows that are not http(s) links are skipped and
counted.

The import is idempotent. Links already in Docket, from an earlier import
or saved since, are matched on their normalised URL and only gain any tags
they were missing; nothing is duplicated, re-dated or fetched again.

Extraction for new items is queued with a gap between each job (10 seconds
by default; **Settings → Import spacing**), so a few thousand links take a
few hours to fill in rather than hammering every site at once. Keep the
queue worker running until the queue drains. Anything that fails (dead
links are common in an old Pocket library) is marked on the item with the
reason. **Tools → Fetch failed and pending items** (or `php artisan
docket:refetch`) tries them all again.

## Installation

Requirements: PHP 8.3+ with `pdo_sqlite` (SQLite built with FTS5, which is
standard on current Debian, Ubuntu and Homebrew), `mbstring`, `fileinfo`,
`dom` and `xml`; `intl` and `zip` recommended. Composer and Node 20+.

Both installers do the same work: create `.env`, generate the app key,
create the SQLite database, run the migrations and create the single owner
account. There is no registration flow.

### Web installer (recommended on a server)

```sh
git clone https://github.com/WebberZone/TheDocket.git docket
cd docket
composer install --no-dev
npm ci && npm run build
```

Point your web server at `public/`, then open
**`https://your-domain/install`**. The wizard checks the server
requirements, then asks for a setup code, the site URL, your name, email
and password, and installs.

The setup code proves you control the server: anyone can find a fresh
deployment's `/install`, so it won't create the account without it. Read
it on the server once the setup page has loaded:

```sh
cat storage/app/install/setup-code
```

The installer only exists while Docket has no account. Once your login
is created it leaves a marker (`storage/app/install/installed`) and
`/install` permanently redirects to the login screen. If the database
can't be read, it answers 503 rather than treating the site as fresh.
Docket has exactly one account: neither installer, nor `db:seed`, will
create a second one.

### Console installer

```sh
git clone https://github.com/WebberZone/TheDocket.git docket
cd docket
composer setup      # deps + frontend build + guided php artisan docket:install
```

The console installer is safe to re-run (`--force`) and never deletes data.
For scripted installs pass the answers as flags:

```sh
php artisan docket:install --url=https://docket.example.com \
    --name="Jane Doe" --email=jane@example.com --password=... \
    --no-interaction   # omit --password to have one generated and shown once
```

Then, for local use:

```sh
php artisan serve
php artisan queue:work   # fetches and extracts saved pages
```

Development server (app + queue + logs + Vite): `composer dev`.

## Deployment (any small VPS)

1. Nginx + PHP 8.3-FPM, a standard Laravel vhost with the root at
   `public/`. HTTPS (Let's Encrypt via `certbot --nginx -d your-domain`) is
   required for the PWA install and share target.
2. Visit `https://your-domain/install` (or run `php artisan docket:install`)
   with the real production URL. Then in `.env` set `APP_ENV=production`,
   `APP_DEBUG=false`, `BACKUP_*` (below) and a real mailer (`MAIL_*`) so
   backup-failure emails arrive.
3. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
4. Queue worker under systemd:

   ```ini
   [Service]
   ExecStart=/usr/bin/php /var/www/docket/artisan queue:work --tries=3 --timeout=300 --max-time=3600
   Restart=always
   User=www-data
   ```

   Keep `--timeout` below the queue's `retry_after` (360 seconds by
   default, `DB_QUEUE_RETRY_AFTER`), or a slow fetch is handed to a second
   worker while the first is still running it.

5. One cron entry drives the scheduler (backups, cleanup, monitor):

   ```
   * * * * * cd /var/www/docket && php artisan schedule:run >> /dev/null 2>&1
   ```

Without a worker, set `QUEUE_CONNECTION=sync` and pages are fetched during
the save request instead: slower saves, and a Pocket import will take as
long as every fetch put together.

## Backups (spatie/laravel-backup)

Daily at 02:00 server time: the SQLite database is dumped, zipped with
`storage/app/private` (the saved article images), verified and shipped to
the `offsite` disk, an S3-compatible bucket with a different provider from
the server (Backblaze B2 or Cloudflare R2). `backup:monitor` emails
`BACKUP_NOTIFY_EMAIL` (or the owner's address) if the newest backup is
more than a day old.

```
BACKUP_ACCESS_KEY_ID=...
BACKUP_SECRET_ACCESS_KEY=...
BACKUP_BUCKET=docket-backups
BACKUP_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
BACKUP_REGION=auto
```

The database in the archive is an SQL dump (`db-dumps/*.sql`, written by
`sqlite3 .dump`), not a database file: it has to be imported into a new
database. Test a restore early, on a copy of the server if you can:

```sh
php artisan backup:run
# download the newest zip from the bucket, then:
unzip Docket/….zip -d /tmp/restore
ls /tmp/restore/db-dumps/                       # one .sql file

# Build a new database from the dump and check it.
sqlite3 /tmp/restore/restored.sqlite < /tmp/restore/db-dumps/*.sql
sqlite3 /tmp/restore/restored.sqlite "PRAGMA integrity_check;"      # expect: ok
sqlite3 /tmp/restore/restored.sqlite "SELECT count(*) FROM items;"
sqlite3 /tmp/restore/restored.sqlite "INSERT INTO items_fts(items_fts) VALUES('rebuild');"

# Swap it in with nothing writing: stop the worker and the site first.
php artisan down
sudo systemctl stop docket-worker        # whatever runs queue:work
mkdir -p /tmp/before-restore && cp database/database.sqlite* /tmp/before-restore/
mv /tmp/restore/restored.sqlite database/database.sqlite
rm -f database/database.sqlite-wal database/database.sqlite-shm
rsync -a /tmp/restore/…/storage/app/private/ storage/app/private/
chown -R www-data: database storage/app/private
php artisan migrate --force             # if the backup predates a release
php artisan up
sudo systemctl start docket-worker
```

Then open a saved article, check its images load, and search for a word
you know is in it. The originals stay in `/tmp/before-restore` until
you're satisfied.

## Security notes

Every saved link is someone else's input, so fetching is treated as
hostile:

- **SSRF**: Docket resolves each hostname itself and refuses to connect if
  any address is loopback, private, link-local (including cloud metadata
  at `169.254.169.254`), carrier-grade NAT, multicast or reserved,
  including IPv6 forms that embed one. The connection is then pinned to
  the address that was checked, so a second DNS answer cannot swap one in.
  Redirects are followed by hand and every hop is checked. Responses are
  capped (5 MB pages, 5 MB images by default), with connect and total
  timeouts and at most five redirects. Inline images go through the same
  client. If the server sends outbound traffic through an HTTP proxy, the
  proxy does its own DNS lookup, so the pinning cannot apply.
- **Stored HTML** is sanitised with an allow-list
  (symfony/html-sanitizer): no scripts, styles, frames, forms, embeds,
  inline SVG, event handlers or `javascript:` links. Downloaded images are
  checked by content, not by the server's `Content-Type`, and only raster
  formats are stored; SVG stays remote. Images are served only to the
  logged-in owner, with `nosniff`.
- **Content-Security-Policy** on every page, as a second lock behind the
  sanitiser: scripts only from this origin or carrying the per-request
  nonce, no `unsafe-inline` or `unsafe-eval`, no inline event handlers or
  style attributes, nothing framing the app, forms posting only here.
  Alpine is its CSP build, so components live in `resources/js` rather
  than inline in the markup; add behaviour there (or as a `data-*` hook in
  `resources/js/behaviours.js`), never as an `onclick` attribute. Images
  may come from any `https:` host, because undownloaded article images
  stay remote. `npm run dev` is allowed automatically while it runs.
- The bookmarklet and share target are `GET` requests, because that is
  how they arrive. A third-party page could link you to `/save?url=…`; the
  worst that does is add a link to your own list.

## Development

```sh
composer test       # php artisan test
composer lint       # Pint
composer stan       # PHPStan (Larastan)
```

The feature tests never touch the network: `Http::preventStrayRequests()`
is on, and DNS goes through a fake resolver (`tests/Support`). The Pocket
importer test uses `tests/Fixtures/pocket_export.csv`; the extraction tests
use `tests/Fixtures/article.html`.

Config lives in `config/docket.php` (env vars use the `DOCKET_` prefix);
the knobs on the Settings page override it from the database.

## Not in version 1

Highlights and notes, a wallabag-compatible API, saving by email, RSS
feeds, and multiple users. The schema leaves room for them: notes and
highlights hang off `items.id`, and multi-user means adding `user_id` and
widening the unique key to `(user_id, normalized_url)`.
