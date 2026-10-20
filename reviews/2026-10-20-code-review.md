**TheDocket — security, logic, and performance review**

Reviewed commit `a95aafa1410a41b020728de59761161e77bbe190` on branch `wz/elegant-dijkstra-lvdz1o`, fetched on 20 October 2026. Application source was not changed. Findings below refer to this checkout, including its committed dependency lockfiles.

The main release blocker is that real hostname fetches fail with the locked Guzzle version, although the mocked test suite passes. The principal security problems concern creating the single owner account and recreating accounts through seeding. Large-library memory use, queue reservations, image handling, and recovery also need changes.

Priority convention: P1 = high, fix before exposing/deploying the affected workflow; P2 = medium, fix in the next corrective release; P3 = low, bounded behaviour defect. Conditional findings state their prerequisite explicitly.

**1. [P1] Real hostname fetches fail because streaming selects an incompatible transport**

Location: [SafeHttpClient.php](../app/Services/Fetching/SafeHttpClient.php#L130).

`send()` combines `stream => true` with `curl => [CURLOPT_RESOLVE => ...]`. The committed lockfile installs Guzzle **8.3.0**. With ordinary `allow_url_fopen=On`, Guzzle selects its PHP stream handler for streaming requests; that handler rejects non-empty cURL options. A real request throws `GuzzleHttp\Exception\InvalidArgumentException` with: `Passing the "curl" request option to the stream handler is not supported because the stream handler ignores cURL options.`

This affects hostname URLs after they pass DNS validation, including the normal article-saving workflow and hostname-based images. The exception is not translated into a `FetchFailedException`; asynchronous jobs eventually reach the generic failure handler, while synchronous saves can return an error. IP-literal URLs use an empty cURL array and have a different path. Installations with `allow_url_fopen` disabled may also select a different transport.

Evidence: reproduced through `SafeHttpClient` without HTTP fakes against an isolated local fixture, using a fake vetted public address. The exception occurs before a network connection. Existing SSRF tests replace the transport with `Http::fake()` and cannot detect this.

Fix: explicitly use a transport that supports address pinning, and enforce response limits while downloading with a bounded sink/progress or write callback. Simply removing the cURL options would remove the intended DNS-rebinding defence. Add an integration test using the real selected handler. The confirmed finding for the locked version is a fetch failure, **not a demonstrated SSRF bypass**.

**2. [P1] The public installer can create multiple owner accounts through an interleaving race**

Locations: [InstallController.php](../app/Http/Controllers/InstallController.php#L80), [Installer.php](../app/Services/Installer.php#L233), [install routes](../routes/install.php#L19).

The only ownership gate checks whether a user exists before entering the installer. Owner creation later performs an unconditional `User::create()` with no shared lock or single-owner database constraint. Two setup requests can both pass the gate before either inserts an owner, then create accounts with different email addresses. Every authenticated account can subsequently access the same articles, exports, settings, and maintenance tools.

Evidence: a deterministic reproduction paused the first request immediately before `createOwner()`, completed a second full setup request, then resumed the first. Both responses succeeded and two accounts existed. This models an actual concurrent interleaving; it is not a live Internet exploit test.

Additionally, setup has no bootstrap secret or origin/CSRF check. Anyone who reaches a fresh publicly exposed deployment can claim its first account. This exposure is limited to setup unless another defect reopens installation; an ordinary request cannot bypass the gate on a healthy installed application.

Fix: require an installation secret provisioned through the server/CLI, serialize installation with a process-shared lock, and recheck ownership inside that lock immediately before changing configuration or inserting an account. Enforce the one-owner invariant independently of a unique email address. Consume a persistent installation marker on success.

**3. [P2] Database errors reopen the installer instead of failing closed**

Location: [Installer.php](../app/Services/Installer.php#L27).

`isInstalled()` catches every `Throwable` from the schema/user query and returns `false`. A locked, temporarily inaccessible, or misconfigured installed database is therefore treated as a fresh deployment. The subsequent POST writes `APP_URL` and owner identity to `.env` before migration/owner creation succeeds. Even a later failure does not roll back those configuration changes. If database access recovers between the check and insertion, an additional account can be created.

Evidence: injecting a database exception makes the installation check return false. Configuration writes preceding database work are visible in the controller. Full exploitation on an installed host depends on the timing and type of database failure; it was not exercised against a production database.

Fix: recognize a deliberately uninitialized installation separately from operational database errors. Return an unavailable/error response on unexpected failures and use a durable installation marker. Do not rewrite live configuration based on an inconclusive database probe.

**4. [P2] Seeding after an owner email change can add an account with a published password**

Locations: [DatabaseSeeder.php](../database/seeders/DatabaseSeeder.php#L16), [docket config](../config/docket.php#L25).

The seeder uses `firstOrCreate()` keyed by the configured owner email and falls back to the public password `change-me-on-first-login`. The supported profile form changes the database email without updating that configuration. Running `db:seed` afterward creates a second account using the old email and the known password when `DOCKET_USER_PASSWORD` is unset, as it is after normal installation. This account can access the entire existing library.

Evidence: created the current owner under a changed email and ran the seeder with the standard password fallback; two accounts existed and the new account's hash matched the published password. This requires an operator to run seeding; it does not occur on every deployment automatically.

Fix: never fall back to a known production password, and refuse to create a user when any owner already exists. Use the installer for explicit owner creation and a separate explicit recovery command for account recovery.

**5. [P2] Queue reservations expire before fetch jobs are allowed to finish**

Locations: [queue.php](../config/queue.php#L43), [FetchItemContent.php](../app/Jobs/FetchItemContent.php#L36).

The database queue defaults to `retry_after=90`, but fetch jobs have `timeout=300` and the deployment worker also uses 300 seconds. With multiple workers, a second worker can reserve a job that has run for more than 90 seconds while the first worker is still processing it. This duplicates requests and image writes, introduces competing item state updates, and can waste retry attempts. Sequential image downloads make jobs exceeding 90 seconds plausible.

Evidence: reserved a database-queue job, advanced the test clock by 91 seconds, and successfully reserved the same job ID again, before its 300-second timeout.

Fix: set the reservation timeout comfortably above the job/worker timeout on every supported backend, and document the relationship. Add per-item overlap protection to handle explicit retries/refetches as well. This duplication requires concurrent consumers; a single worker does not run two copies simultaneously.

**6. [P2] Lists, bulk operations, and exports hydrate full article bodies unnecessarily**

Locations: [ItemController.php](../app/Http/Controllers/ItemController.php#L54), [BulkItemController.php](../app/Http/Controllers/BulkItemController.php#L40), [Exporter.php](../app/Services/Exporter.php#L106).

List queries select every column, including `content_html` and `content_text`, even though rows only render metadata. Bulk operations load up to 1,000 complete models. The exporter uses `lazy(200)`, which retrieves a full batch of 200 article models, despite its comment describing one-at-a-time memory usage. CSV does not need either body column; JSON needs them but its batch size can still be excessive.

Evidence: the list query allocated **16,668,408 bytes** for 30 items with only 256 KiB HTML and 256 KiB text each. This excludes much of the rest of the request. The same payload size is roughly 100 MiB for 200 export rows or 500 MiB for 1,000 bulk rows before additional overhead. Those batch estimates are arithmetic extrapolations, not full endpoint benchmarks.

Fix: select explicit metadata columns for lists and CSV, including the search scope which currently forces `items.*`. Process bulk actions in small ID batches or use suitable set-based updates, retaining deletion cleanup. Reduce the JSON export batch and prefer stable keyset iteration where possible. Avoid loading old article bodies into refetch commands/jobs when they are unnecessary.

**7. [P2] Failed image downloads do not consume the per-article limit**

Location: [ImageLocaliser.php](../app/Services/ImageLocaliser.php#L76).

The limit compares `count(array_filter($stored))`, so only successful stored filenames count. Failed, oversized, invalid, or unsupported images return null and leave the budget unchanged. An article containing many distinct failing images can therefore initiate far more requests than the configured limit and occupy a worker until its hard timeout. Repeated filtering also introduces unnecessary work as the map grows.

Evidence: with `images.max_per_item=2`, an article containing seven distinct failing image URLs caused seven HTTP requests.

Fix: keep separate counters for attempted URLs and successfully stored images. Bound attempts, aggregate downloaded bytes, and elapsed image-processing time; stop before the overall job deadline.

**8. [P2] Image storage failure replaces a working remote URL with a broken local URL**

Location: [ImageLocaliser.php](../app/Services/ImageLocaliser.php#L114).

The local disk has `throw=false`. `store()` ignores the boolean returned by `Storage::put()` and always returns a filename. A full disk or permission failure therefore causes HTML to be rewritten to a local image route whose file does not exist. The lead-image field can also claim a missing local file. The article is still marked fetched successfully.

Evidence: mocked a failed `put()` returning false; `localise()` nevertheless replaced the remote `src` with the local image route.

Fix: check the return value or use throwing writes and handle their exceptions. Preserve the remote URL on failure and log the storage failure so operators can diagnose it.

**9. [P2] A failed refresh makes a previously saved article unreadable**

Locations: [FetchItemContent.php](../app/Jobs/FetchItemContent.php#L148), [Item.php](../app/Models/Item.php#L197).

Refetch failures set `fetch_status=failed`, while `hasArticle()` requires `fetch_status=fetched`. Consequently, retrying a good stored article after its original site disappears hides the existing saved body and displays a failure panel. The body is still in the database, but the normal reader cannot display it. Bulk refetch can affect many articles at once.

Evidence: an item initially had a readable stored article; a simulated 404 during refetch left `content_html` intact but changed `hasArticle()` to false.

Fix: distinguish stored-content availability from the latest fetch attempt. Keep rendering the last successful version, with a refresh warning, and replace it only after successful extraction and storage.

**10. [P2] Refetch can skip items because offset pagination iterates a changing result set**

Location: [DocketRefetchCommand.php](../app/Console/Commands/DocketRefetchCommand.php#L45).

The default command selects failed/pending items using `lazy()`, whose next batch is offset-based. Successful processing removes items from that selection. Under the documented synchronous queue configuration, the first 1,000 items can finish while the command is still iterating them; the next query skips 1,000 rows in the now-smaller result set. Active asynchronous workers can cause the same class of movement. `--all` does not have this particular status-filter issue.

Evidence: with 1,001 failed items and successful synchronous fetches, the command processed the first 1,000 and left the final item's attempt count at zero.

Fix: use `lazyById()`/`chunkById()` so progress follows a stable key, or take a stable snapshot of IDs before dispatching.

**11. [P2] Accepted tag names can generate unreachable routes**

Location: [Tag.php](../app/Models/Tag.php#L56).

Tag slugs replace spaces with hyphens but retain `/`. A normal name such as `work/personal` is accepted and stored, then generates `/tags/work/personal`, which does not match the tag route. Renaming/deleting through the corresponding slug routes is also affected. URL-reserved characters should not serve directly as unconstrained identities.

Evidence: created `work/personal`, generated its route through Laravel, and received a 404 while authenticated.

Fix: route using a numeric ID or a URL-safe opaque identifier. Keep the case-insensitive name identity separate from its display/routing representation. Include punctuation, Unicode, and whitespace/hyphen collision cases in tests.

**12. [P2] CSV exports preserve attacker-controlled spreadsheet formulas**

Location: [Exporter.php](../app/Services/Exporter.php#L30).

Article titles and tags can originate in untrusted web pages/imports. `fputcsv()` escapes CSV syntax but does not neutralize formulas. A title beginning with `=`, for example, remains a formula when the user opens the export in a spreadsheet that evaluates it. Formula impact depends on the spreadsheet's security policy and user interaction; no spreadsheet-side execution or exfiltration was tested.

Evidence: an item titled `=1+1` exports as a cell containing exactly `=1+1` after CSV parsing.

Fix: offer a spreadsheet-safe export that treats strings as text, while retaining a clearly distinguished lossless interchange export if Pocket round-trip fidelity is required. Test leading whitespace/control characters as well as standard formula prefixes. Quoting alone is insufficient.

**13. [P2] Documented backup restoration uses the wrong artifact and format**

Location: [README.md](../README.md#L221).

The README moves `db-dumps/database.sqlite` directly into place. The locked Spatie SQLite dumper invokes `sqlite3 .dump`, and the backup package names the artifact with a `.sql` extension (normally a name like `sqlite-database.sql`). It is SQL text, not an SQLite database file. The documented path will normally be absent; substituting the SQL file into that `mv` command creates an unusable database in place of the live one.

Evidence: used the installed dumper on an isolated SQLite fixture. Its output began with `PRAGMA foreign_keys=OFF; BEGIN TRANSACTION; ...`. Opening that output as an SQLite database and querying it returned `file is not a database`.

Fix: document extracting the actual SQL dump and importing it into a new database using `sqlite3 restored.sqlite < dump.sql`. Stop writes/workers, validate integrity and representative data, preserve the original database and any WAL-related state, then replace the live database under a controlled restore procedure. Test the complete restoration process, including article images and FTS search. The full off-site S3 backup/restore path was not exercised.

**14. [P3] Disabling image downloads still downloads the lead image**

Location: [FetchItemContent.php](../app/Jobs/FetchItemContent.php#L126).

The inline localiser respects `images.enabled` and `images.max_per_item=0`; the unconditional lead-image `store()` call respects neither. Users can disable inline downloads and still incur network traffic and private-disk storage for the lead image. The lead image can also be fetched again when it was already downloaded inline.

Evidence: with downloads disabled and the configured limit set to zero, processing the article fixture still issued the lead-image request and saved `lead_image_path`.

Fix: apply one image-download policy and budget to both inline and lead images, and reuse a stored inline copy when URLs match. If lead images are intentionally exempt, expose that distinction clearly in settings.

**Validation and limits**

- Existing PHPUnit suite: **195 tests passed, 690 assertions**.
- Targeted review reproductions: **13 tests passed, 26 assertions**. These assert the observed defective behaviours; passing does not mean the defects were fixed.
- PHPStan: passed. Pint: passed, 123 files.
- Frontend: `npm ci --ignore-scripts` and `npm run build` passed.
- `composer validate` passed. Composer audit reported no advisories or abandoned packages; npm audit reported zero vulnerabilities. These are dependency database results, not proof that the application is secure.
- Validation used PHP **8.4.26** in an isolated Docker container with the repository's locked dependencies. The advertised PHP 8.3 baseline was not separately executed.
- Reviewed application services/jobs/controllers, authentication and installer boundaries, routing/middleware, models/migrations/FTS, views/reader/service worker, import/export, queue and backup configuration, CI, and existing tests. There were no AGENTS.md instructions in this checkout or its workspace parents.
- No production host, credentials, real user data, external exploit target, or off-site bucket was used. The real-handler reproduction used only a local fixture and fails before connecting.
- No confirmed stored-XSS or SQL-injection finding emerged from this review. Existing HTML allowlisting, parameterized FTS queries, raster-content MIME checks, authentication on image routes, and allowlisted maintenance commands are useful protections. This does not establish the absence of undiscovered vulnerabilities.

Reproduction source: [ReviewRegressionTest.php](evidence/ReviewRegressionTest.php). Execution output: [docket-review-reproductions.txt](evidence/docket-review-reproductions.txt). Backup-format fixture: [backup-format.php](evidence/backup-format.php).

Suggested implementation order: repair and integration-test the fetch transport; secure ownership creation and seeding; correct queue timing; bound image work and memory use; preserve existing articles on refresh; repair refetch iteration, tag routing, export safety, and restore documentation.
