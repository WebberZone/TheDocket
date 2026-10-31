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
- **Offline reading**: the installed app keeps the newest unread articles
  (30 by default), with their stored images, on each device, and refreshes
  them whenever it is opened online.
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

## Reading offline

Install Docket on the device (**Add to Home screen** on Android or iOS, or
the install button in a desktop browser) and open it while online. Every
time a logged-in page opens with a connection, the service worker updates
its copies of the newest unread articles (**Settings → Articles kept for
offline reading**, 30 by default; 0 keeps none) and their stored images.

Offline, those articles open as normal from the list on the offline page or
from any link to them. Your place in an article is remembered and sent when
the connection comes back. Archiving, favourites, tags and search need a
connection. Images that were never downloaded to the server (past the
per-article limits, or SVG) stay remote and don't show offline.

An article leaves the device at the next sync once it is archived or
deleted, or drops out of the newest unread. Logging out deletes every
offline copy in that browser.

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
few hours to fill in rather than hammering every site at once. The
scheduler cron drains the queue; nothing else needs running. Anything that fails (dead
links are common in an old Pocket library) is marked on the item with the
reason. **Tools → Fetch failed and pending items** (or `php artisan
docket:refetch`) tries them all again.

## Installation

Docket aims to be as easy to self-host as a shared-hosting PHP app:
download, upload, open `/install` on your own domain, add one cron line.
There is no registration flow — the installer creates the single owner
account.

### Requirements

- A web server (nginx or Apache) serving the `public/` directory
- PHP 8.3+ (FPM or CGI) with `pdo_sqlite` built with FTS5 (standard on
  current Debian, Ubuntu and most shared hosts), `mbstring`, `fileinfo`,
  `dom`, `xml` and `curl`; `intl` and `zip` recommended
- Composer and Node 20+ are **only** needed when installing from the
  source branch — the release zip and the `dist` branch come prebuilt

`php artisan docket:check-requirements` prints the full checklist and
exits non-zero when a required piece is missing.

### A) Shared hosting (release zip)

1. Download `docket-<version>.zip` from the
   [Releases page](https://github.com/WebberZone/TheDocket/releases)
   (releases appear from the first tagged release).
2. Unzip and upload the contents to your hosting, for example into
   `~/docket/`, with FTP or the control panel's File Manager.
3. Set the document root to the `public/` folder inside it. On cPanel
   this is the domain's *Document Root* setting; on Plesk it is
   *Document root* under Hosting Settings. Pointing the domain at the
   top-level folder will not work.
4. Open **`https://your-domain/install`** in your browser. The wizard
   checks the server, then asks for a setup code. The code lives in
   `storage/app/install/setup-code` inside your Docket folder — open it
   in the control panel's File Manager (or `cat` it over SSH).
5. Fill in the site URL, timezone, your name, email and password.
6. The last screen shows **one cron line** — add it in your control
   panel's *Cron Jobs* section (run every minute; paste the command part
   if the panel asks for schedule and command separately).

### B) VPS — one command

On a fresh Ubuntu or Debian server:

```sh
curl -fsSL https://raw.githubusercontent.com/WebberZone/TheDocket/master/tools/install.sh -o docket-install.sh
sudo bash docket-install.sh
```

It installs nginx, PHP 8.3+ FPM and the dependencies, fetches the code
(prebuilt `dist` branch when available), writes the nginx vhost, obtains
a Let's Encrypt certificate, adds the cron line for `www-data`, then
prints the install URL and setup code. Point your domain's DNS A record
at the server first. Every prompt has an environment override for
scripted runs (`DOCKET_DOMAIN`, `DOCKET_PATH`, `DOCKET_HTTPS`,
`DOCKET_EMAIL`, `DOCKET_YES=1`).

### C) VPS — manual

```sh
git clone https://github.com/WebberZone/TheDocket.git /var/www/docket   # or --branch dist
cd /var/www/docket
composer install --no-dev --optimize-autoloader   # not needed on dist
npm ci && npm run build                           # not needed on dist
```

Point nginx (root `public/`, `try_files` to `index.php`) or Apache at the
site, then either open `https://your-domain/install` or run the console
installer:

```sh
php artisan docket:install
# or fully scripted:
php artisan docket:install --url=https://docket.example.com \
    --name="Jane Doe" --email=jane@example.com \
    --timezone=Europe/London --no-interaction   # omits --password → generates one
```

The setup code, if you install in the browser, is printed by
`php artisan docket:setup-code` and also lives in
`storage/app/install/setup-code`.

### Background tasks (cron)

Docket works with no cron at all: articles you save are fetched there and
then, after the response. The scheduler only adds the background work —
backups, Pocket imports and bulk refetches — and there are two ways to
drive it.

**Option 1 (recommended): one cron line**

```
* * * * * cd /var/www/docket && php artisan schedule:run >> /dev/null 2>&1
```

(Adjust the path and PHP binary — the installer's completion page and
Settings → Background tasks show the exact line for your server.) It runs
the scheduler every minute, which drains the queue, beats the heartbeat
and runs the backups. No systemd worker is needed. For instant fetches
you may additionally run `php artisan queue:work` under
systemd/supervisor and set `DOCKET_QUEUE_VIA_SCHEDULER=false` in `.env` —
entirely optional.

**Option 2: no cron on your host?**

Settings → Background tasks shows a secret **cron URL**. Point a free
web-cron service (cron-job.org, UptimeRobot, or your host's "URL cron")
at it once a minute; each hit drains the queue and runs the scheduler.
Keep the URL secret — anyone who has it can trigger your tasks; the
Regenerate button invalidates it instantly. Caveat, as with FreeScout:
on hosts that disable `proc_open`/`exec`, the scheduler's own
sub-processes can't run — the queue still drains (it runs inside the web
request), but backups can fail there.

If off-site backups are configured and the scheduler stops running, a
warning banner appears at the top of every page until it runs again;
Settings → Background tasks always shows the current status.

### The setup code

The web installer asks for a code before creating your account: anyone
can stumble on a fresh `/install`, so the code proves you control the
server. It is created on demand, one per deployment, and retired once
installation finishes. Find it at `storage/app/install/setup-code`, or
run `php artisan docket:setup-code`.

The installer only exists while Docket has no account. Once your login
is created it leaves a marker (`storage/app/install/installed`) and
`/install` permanently redirects to the login screen. If the database
can't be read, it answers 503 rather than treating the site as fresh.
Docket has exactly one account: neither installer, nor `db:seed`, will
create a second one.

## Updating

On a git checkout, `tools/update.sh` pulls the newest code, snapshots the
database first, rebuilds dependencies and assets when not on `dist`,
migrates, warms the caches and brings the site back up. Add it to cron
for nightly updates:

```
15 4 * * * /var/www/docket/tools/update.sh --yes >> /dev/null 2>&1
```

On shared hosting (zip install), upload the newest release zip over the
top (your `.env`, database and saved images are not in the zip, so they
are kept), then open **Tools** and run **Run migrations** followed by
**Clear cache**.

## Troubleshooting

- **Warning banner about backups not running** — off-site backups are
  configured but the scheduler cron has stopped. Add the cron line or set
  up the cron URL; the warning clears within a minute of it running.
- **Imports or refetches never start** — background tasks aren't running:
  Settings → Background tasks has the cron line and the cron URL.
- **500 errors** — read `storage/logs/laravel.log`; wrong file ownership
  is the usual cause (`storage/`, `bootstrap/cache/` and `database/` must
  be writable by the web user).
- **"SQLite with FTS5" fails the requirements check** — the host's PHP
  SQLite was built without FTS5; a different PHP build is needed.
- **Blank page or 404 on every URL** — the document root is the app
  folder rather than `public/`; fix the vhost/panel setting.
- **Pages save but never get content** — check Settings → Background
  tasks and `php artisan docket:check-requirements`.

## Backups (spatie/laravel-backup)

Daily at 02:00 in APP_TIMEZONE: the SQLite database is dumped, zipped with
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
