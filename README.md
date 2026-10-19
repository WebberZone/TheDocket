# Docket

A single-user cost/expense tracker you can self-host. Snap a receipt on
the phone, have it logged in two taps; year-end is a CSV export mapped to
your accountant's nominal codes. Built for Beyond Develop Ltd, open-sourced
under the [MIT licence](LICENSE) for anyone who wants the same.

Laravel 13 · PHP 8.3 · SQLite · Breeze (Blade) · Tailwind · Alpine.

## What it does

Everything the old "Accounts Tracker" spreadsheet did, plus receipt capture:

- **Three ledgers** sharing one shape (single-table inheritance on
  `transactions`): **Expenses**, **Income** and **Cash flows** (share
  capital / director loans). CRUD, filters, GBP totals and CSV export per
  ledger. Amounts are net + VAT; for non-GBP entries give either an FX rate
  or the actual GBP charged from the statement — the other is derived.
- **Capture** (`/capture`): tap one takes the photo — a draft expense is
  created and the image goes through a queued OCR job (Google Vision, or
  local tesseract as the free fallback); tap two confirms the pre-filled
  form. Installs to the phone as a PWA (`start_url` is the capture screen).
- **Reports** (`/reports`): the Summary sheet — per-year Income / Expenses /
  Profit / Cash flows, expense matrices by report group (accountant view),
  by category and by payment method, with a personal-cards (to reimburse)
  subtotal.
- **Balances** (`/balances`): snapshot balances per account+currency,
  pivoted accounts × dates — the Balances sheet.
- **Ledger** (`/ledger`): any payment method + currency as a statement with
  a running balance in the original currency — the PayPal USD/EUR sheets,
  generalised.
- **Categories** are two-level: entry-level name (e.g. "Website Expenses")
  plus a `report_group` rollup ("Advertising and PR") and an `account_code`
  for the accountant's chart.
- **Receipts**: many per expense, stored on the private local disk
  (`storage/app/private`), served only through an authed route.
- **Money** is integer pence everywhere. Floats never touch storage.
- **No soft deletes** — records are kept forever; deleting a transaction is
  a deliberate, permanent act (the six-year record trail relies on simply
  never deleting).

### Importing the old tracker

`php artisan docket:import-tracker <dir>` loads CSVs exported from the
spreadsheet (income, expenses, cash flows, balances) and prints per-ledger
totals to verify against the workbook. The export script lives in the
session scratchpad (`export_csv.py`); it reads a *copy* of the xlsx and
never touches the original. Already run against the tracker as of
July 2026 — totals matched to the penny.

## Installation

Requirements: PHP 8.3+ (with `pdo_sqlite`, `mbstring`, `fileinfo`),
Composer, Node 20+. Optional: `tesseract` or Google Vision credentials for
receipt OCR, Python 3 for the Accounts Tracker XLSX export.

Both installers do the same work — create `.env`, generate the app key,
create the SQLite database, run the migrations and create the single owner
account (there is no registration flow) — and both offer to seed a starter
taxonomy of categories, business units and payment methods you can rename
in Settings, or leave you to start empty.

### Web installer (recommended on a server)

```sh
git clone https://github.com/WebberZone/TheDocket.git docket
cd docket
composer install --no-dev
npm install && npm run build
```

Point your web server at `public/`, then open **`https://your-domain/install`**
in a browser. The wizard checks the server requirements (PHP version,
extensions, writable directories), then asks for your site URL, name, email
and password, and installs. It only exists while Docket has no user account —
as soon as your login is created, `/install` permanently redirects to the
login screen.

### Console installer

```sh
git clone https://github.com/WebberZone/TheDocket.git docket
cd docket
composer setup      # deps + frontend build + guided php artisan docket:install
```

The console installer is safe to re-run (`--force`) and never deletes data.
For scripted/non-interactive installs pass the answers as flags:

```sh
php artisan docket:install --url=https://docket.example.com \
    --name="Jane Doe" --email=jane@example.com --password=... \
    --no-interaction   # omit --password to have one generated and shown once
```

Then, for local use:

```sh
php artisan serve        # or use Herd/Valet
php artisan queue:work   # needed for receipt OCR jobs
```

For receipt OCR set `GOOGLE_VISION_CREDENTIALS` in `.env`, or just install
`tesseract` for the free local fallback; with neither, uploads simply skip
extraction.

Tests: `php artisan test`. Dev server (app + queue + logs + Vite):
`composer dev`.

## Deployment (any small VPS; mine is OCI Always Free, Ubuntu)

1. Nginx + PHP 8.3-FPM, standard Laravel vhost with root at `public/`.
   Let's Encrypt via `certbot --nginx -d your-domain`. HTTPS is required
   for the PWA install + camera capture.
2. Visit `https://your-domain/install` (or run `php artisan docket:install`)
   and give it the real production URL and your login details, then in
   `.env` set `APP_ENV=production`,
   `APP_DEBUG=false`, `GOOGLE_VISION_CREDENTIALS` (or install tesseract),
   `BACKUP_*` (see below), and a real mailer (`MAIL_*`) so backup-failure
   emails actually arrive.
3. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
4. Queue worker under systemd:

   ```ini
   [Service]
   ExecStart=/usr/bin/php /var/www/docket/artisan queue:work --tries=3 --max-time=3600
   Restart=always
   User=www-data
   ```

5. One cron entry drives the Laravel scheduler (backups, cleanup, monitor,
   and the OCI keep-alive burn that stops idle reclaim):

   ```
   * * * * * cd /var/www/docket && php artisan schedule:run >> /dev/null 2>&1
   ```

## Backups (spatie/laravel-backup)

Daily at 02:00 server time: SQLite is dumped to a file, zipped with the
receipts directory, verified, and shipped to the `offsite` disk — an
S3-compatible bucket that **must live outside OCI** (Backblaze B2 or
Cloudflare R2; the OCI account itself is the risk being insured against).
Retention: 14 daily / 8 weekly / 24 monthly / 7 yearly. `backup:monitor`
emails `BACKUP_NOTIFY_EMAIL` if the newest backup is older than a day.

Fill in `.env`:

```
BACKUP_ACCESS_KEY_ID=...
BACKUP_SECRET_ACCESS_KEY=...
BACKUP_BUCKET=docket-backups
BACKUP_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
BACKUP_REGION=auto
```

### Test a restore now, not in year three

```sh
php artisan backup:run                  # take one
# download the newest zip from the bucket, then:
unzip Docket/….zip -d /tmp/restore
mv /tmp/restore/db-dumps/database.sqlite database/database.sqlite
rsync -a /tmp/restore/…/storage/app/private/ storage/app/private/
php artisan migrate:status              # sanity-check it boots against the restored DB
```

Open the app, confirm a known expense and its receipt image are present.

## Out of scope, deliberately

Invoicing, bank feeds, double-entry, VAT/MTD, HMRC APIs. Company is not
VAT-registered and outside MTD; this is record-keeping that feeds the CT600,
nothing more.
