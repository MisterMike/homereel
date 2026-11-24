# HomeReel

Private, self-hosted family videos. Milestone 1 implements administrator uploads → private originals → queued inspection → authenticated playback. There is no public registration and nothing is deployed automatically.

## Stack

PHP 8.3+ (64-bit), Laravel 13, Filament 5 / Livewire, Blade, Alpine ES modules, Tailwind CSS 4, Vite, MariaDB in production, Laravel database queues, FFmpeg / FFprobe. Composer locks are resolved against PHP 8.3.37; both dependency lockfiles are committed. DDEV uses MariaDB to match production; SQLite remains available for installations without DDEV and isolated tests. No Redis, Docker or permanent worker is required. Existing DDEV configuration is optional.

## Local installation

Install PHP 8.3+, Composer 2, Node.js 22.12+ (or 24), FFmpeg and FFprobe. Required extensions: ctype, curl, dom, fileinfo, filter, hash, intl, mbstring, openssl, pcre, PDO, session, tokenizer, XML and zip. Use pdo_sqlite locally or pdo_mysql for MariaDB. CLI workers should have pcntl and posix for enforced job timeouts; confirm availability on hosting.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan filament:assets
npm ci
npm run build
php artisan app:create-admin
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://localhost:8000 and http://localhost:8000/admin. The admin command prompts for the password without placing it in shell history. It creates a new account; it does not elevate an existing email. There are no seeded credentials. To add a prototype family member, an operator can create a User via Tinker with name, email and a strong password; is_admin remains false. There is no user-management UI in this milestone.

In separate terminals:

```sh
npm run dev
php artisan queue:work database --stop-when-empty --max-time=50 --tries=3 --timeout=150
```

The second command exits after draining the queue. For active local development, `php artisan queue:work database --tries=3 --timeout=150` can stay running. Restart it after changing application code. A production build means Vite need not run for the app to work.

### Optional existing DDEV setup

```sh
cp .env.example .env
ddev start
ddev composer install
ddev artisan key:generate
ddev artisan migrate
ddev artisan filament:assets
npm ci
npm run build
ddev artisan app:create-admin
ddev artisan queue:work database --stop-when-empty --max-time=50 --tries=3 --timeout=150
```

Visit https://homereel.ddev.site. DDEV configures the local MariaDB database (`db`) in `.env` on startup. Copy `.env.example` before `ddev start`, as shown above; do not switch an existing library between SQLite and MariaDB without migrating its data. FFmpeg is included through webimage_extra_packages on a fresh DDEV build. An existing container may require `ddev restart`. DDEV serves PHP directly; `artisan serve` is unnecessary. For Vite live reload on DDEV, expose the Vite port and configure its HTTPS origin as appropriate, or use `npm run build`.

## Configuration

Use `.env.example`; never commit `.env`, session data, databases or media. SQLite uses database/database.sqlite by default. For MariaDB set DB_CONNECTION=mysql and uncomment/configure DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. Use a dedicated empty test database for database tests.

| Variable | Default / purpose |
| --- | --- |
| APP_KEY | Generate once; retain across deployments and backups |
| APP_URL | Application origin; HTTPS in production |
| SESSION_SECURE_COOKIE | false locally; **true** on HTTPS staging/production |
| SESSION_ENCRYPT | true; cookies remain HttpOnly and SameSite=Lax |
| QUEUE_CONNECTION | database; required at upload finalization |
| DB_QUEUE_RETRY_AFTER | 300 seconds; greater than worker job timeout |
| VIDEO_STORAGE_ROOT | storage/app/videos; absolute private path recommended for deployments |
| VIDEO_MAX_BYTES | 8589934592 (8 GiB), integer on 64-bit PHP |
| VIDEO_CHUNK_REQUESTS_PER_MINUTE | 1200 per administrator; separate from upload control requests |
| VIDEO_CHUNK_BYTES | 8388608 (8 MiB); keep below 32 MiB and hosting request limits |
| VIDEO_STORAGE_LIMIT_BYTES | 429496729600 (400 GiB reserved original allowance) |
| VIDEO_UPLOAD_HOURS | 48; fixed session expiry from creation |
| FFPROBE_PATH / FFMPEG_PATH | Executable names, or absolute executable paths |
| VIDEO_THUMBNAILS | false; opt in only where CPU use is permitted |
| VIDEO_PROCESS_TIMEOUT | 60 seconds per process; align worker timeout and retry_after |

Keep the database queue on the same database connection as the app (leave DB_QUEUE_CONNECTION unset). Job insertion and upload completion share one transaction. Reserve account space beyond the application quota for thumbnails, logs, DBs, backups and other hosted sites. Quotas count upload and optimization reservations, originals, optimized copies, and retained deleted files; they are not an account-wide disk monitor. Monitor physical free space separately.

## Upload architecture and protocol

`ResumableUploads` separates upload management from controllers. `LocalUploads` uses Laravel's private videos filesystem disk. `VideoInspector` owns local processing; `VideoDelivery` owns the playback/thumbnail response. Video records retain provider identity and original metadata. A future provider replaces these bindings and processing jobs without changing templates or policies. No speculative provider implementation is included.

All endpoints are session-authenticated, administrator-only, CSRF-protected and throttled (1200 chunks/minute/account, 120 status/finalization requests/minute/account, and 30 session creations/hour). Sessions belong strictly to their creator, including when another administrator knows their ID. No server path is returned.

1. `POST /uploads` with JSON `{filename,title,size,mime_type}` returns 201 and `{id,filename,size,offset,chunk_bytes,status,expires_at}`. Allowed declared MIME/extension pairs cover MP4/M4V, MOV, WebM and MKV. Finalization also sniffs actual content; the queued probe validates a real video stream.
2. `GET /uploads/{id}` returns the confirmed byte offset and expiry. The database offset is authoritative.
3. `PATCH /uploads/{id}` sends a raw application/octet-stream body, Content-Length, Upload-Offset (decimal), Upload-Checksum (64 hex SHA-256 characters), Accept: application/json and X-CSRF-TOKEN. Chunks are sequential and no larger than the session's chunk_bytes. The browser sets Content-Length automatically.
4. A successful PATCH returns 200 with the new confirmed offset. 409 means stale offset or a busy media lock: query GET before retrying. Replayed chunks are rejected rather than appended again. 422 means invalid length, checksum or media; 410 means closed/expired. 401/419 means sign in again. 429 includes Retry-After; the browser waits and retries automatically, and Pause cancels the wait. Errors never advance the confirmed offset.
5. `POST /uploads/{id}/finish` validates offset, actual size and a bounded MIME sample, then atomically renames the original and inserts the Video and database job. Returns `{video_url,status}`. Repeating it returns the same video without duplicating jobs. It does not hash or copy the whole video.

Each upload uses one private temporary file. Nonblocking filesystem flock serializes writes, finalization, processing and cleanup for the same media ID. Quota changes also share a lock. Locks require a single host with reliable flock; never deploy this implementation across nodes without replacing that coordination. Lock files intentionally persist to avoid inode races and are tiny. Stream reads/writes use 64 KiB buffers. SHA-256 is computed incrementally per chunk; rollback truncates unconfirmed bytes. After a crash the next chunk truncates any tail beyond the saved offset. fflush/fsync precede offset confirmation. A rename followed by DB failure is recoverable by retrying finalization; cleanup warns about and retains any uncommitted original.

Alpine shows confirmed progress, pause/resume, errors and completion. One current upload is remembered per browser/account in localStorage; no file bytes or credentials are stored there. Resume after reload requires the same file's metadata and first/last 64 KiB fingerprint. This guards accidental mismatches, not malicious file substitution; chunk checksums protect transmitted bytes, not a trusted whole-original identity. Resume always rechecks the server. Start a different upload leaves the abandoned session for expiry cleanup. HTTPS or localhost is required for Web Crypto. Proxy/PHP buffering may spool one chunk before Laravel sees it; it must not buffer the whole video.

```sh
php artisan uploads:cleanup
php artisan schedule:run
```

Cleanup removes expired temporary files and marks sessions expired, under the same upload lock. Completed originals are never cleaned as incomplete uploads. Session rows are retained for diagnostics. If an uncommitted original is reported, review logs, extend that session's expires_at through Tinker, then retry finalization from its owner account; do not delete the only copy.

## Processing and playback

The database queue runs FFprobe with argument arrays to extract duration, resolution, codecs and container. Processes permit only the local file protocol, no network fetching. FFmpeg thumbnails use one thread, one frame and a 640px target width when enabled. Probe failures retain the original, mark failed and retry up to three times. Thumbnail failure is logged but does not prevent playback. Administrators can choose a per-video thumbnail time in seconds on the edit page, or leave it blank for automatic selection (5 seconds, or one third of a short clip). Saving a changed time or clicking Generate thumbnail queues regeneration. The current preview stays available until a new image has been generated successfully; failed attempts retain the previous preview and original. Refresh the edit page after the worker runs to see the new preview. Failed videos can be retried from their Filament edit page. Optional MP4 optimization is described below.

MP4/H.264/AAC is conservatively marked compatible; codec support can still vary by device and encoding profile. Other accepted originals show a playback warning. Non-fast-start MP4 may require extra seeks. Original and optimized metadata and files remain distinct.

### Optional optimized MP4

Open **Admin → Video settings** to enable MP4 conversion and optionally automatically convert new uploads. Enable it only where sustained FFmpeg CPU use is permitted. Settings are stored in the database and read for each job, without a worker restart. Before the first admin save, `VIDEO_OPTIMIZATION_ENABLED` and `VIDEO_OPTIMIZATION_AUTO` supply the defaults; after saving, the admin settings take precedence over these environment defaults. Automatic conversion queues a copy after successful inspection; otherwise use **Create optimized MP4** on the Filament video edit page (also works for existing videos). Production environment defaults are off. Local DDEV defaults are enabled. Disabling conversion lets running conversions finish, cancels queued conversions when a worker picks them up, and keeps existing optimized copies available. Cancelled videos can be retried from their edit page after re-enabling conversion. Refresh the edit page to see queued / processing / ready / failed status; failures offer a retry action.

One separate private MP4 is generated with H.264 / AAC, yuv420p, CRF 23, veryfast preset, up to 1280×720 without upscaling, capped video bitrate 2500 kbit/s, stereo audio 128 kbit/s, one encoding thread, and faststart metadata. This is a single rendition, not HLS or adaptive streaming. Already compact originals may not become smaller. Rotation is handled by FFmpeg; unusual HDR, interlaced or anamorphic sources may need a later specialized conversion pipeline.

Run its **separate queue connection**:

```sh
php artisan queue:work transcoding --queue=transcoding --stop-when-empty --max-time=50 --tries=1 --timeout=3690
# DDEV:
ddev artisan queue:work transcoding --queue=transcoding --stop-when-empty --max-time=50 --tries=1 --timeout=3690
```

`VIDEO_OPTIMIZATION_TIMEOUT` defaults to 3600 seconds per FFmpeg process. Job timeout is that value + 90; the dedicated database connection reserves jobs for that value + 180. Keep the worker timeout aligned and do not consume the transcoding queue through the ordinary database connection (its retry_after is only 300 seconds). Conversion attempts are not automatically repeated after a failure. Use the edit-page retry after checking logs. Laravel's scheduler starts both queue workers independently every minute, in the background with separate cache overlap locks and timeouts derived from the video settings. DDEV's `.ddev/web-build/time.cron` only invokes `schedule:run`; after editing that file run `ddev restart` to install the new crontab. Remove legacy direct worker cron entries when switching to the scheduler to avoid duplicate workers. `--max-time` only stops between jobs; a conversion can run much longer than a minute.

Storage is reserved before queueing based on duration and capped bitrate. The reservation and completed copy count towards the same allowance as uploads. Encoding writes a unique private `.part.mp4`, verifies format, duration and size with FFprobe, then publishes it by rename. Failures discard only the incomplete derivative and release its reservation. Originals stay ready and usable throughout. Separate encode locks prevent concurrent encodes and permanent purges while encoding; thumbnails need not wait for an entire conversion. A hard machine crash may leave a partial derivative until the expired queue reservation is processed by a subsequent worker; inspect failed jobs and private disk usage after an outage. Keep workers running to resolve these reservations.

The player defaults to the optimized copy once published. **Optimized / Original** buttons preserve playback position, speed, volume and pause state while changing the private source; a short buffering pause is expected. Until then it plays the original. Both variants use the same authenticated stream route, policy and range implementation (`?quality=optimized` or `?quality=original`); unknown variants are rejected and unpublished variants cannot be streamed. An incompatible original may still fail when explicitly selected. Permanent operator purge removes both copies; soft deletion hides both immediately.

`VideoPolicy` is the authorization boundary. Administrators see all non-deleted videos. Family members see only ready videos explicitly marked “Available to family members” in Filament. New videos are private to administrators until that toggle is enabled. The gallery query uses the same visibility rules. Every original and thumbnail request rechecks authorization. Originals and thumbnails are outside public/; no storage:link or public media URLs exist. Local avatars and fonts avoid external requests for personal display data.

LocalDelivery supports GET, HEAD, single ranges, open-ended and suffix ranges, Content-Length, Content-Range, Accept-Ranges, 206 and 416. HEAD ignores Range per HTTP semantics. Unsupported multipart or malformed ranges return 416; If-Range requests get the full representation because no validator is advertised. Reads are bounded to 64 KiB. Media responses are private/no-store, nosniff and have no untrusted filename in response headers.

**PHP streaming is a development/small-scale proof of concept.** Every active stream occupies a PHP worker and may hit web-server/FPM timeouts; short range requests can seek but long playback may be cut off on Hostpoint. No X-Sendfile/X-Accel support is presumed. Confirm authorized server delegation or adopt signed provider delivery behind VideoDelivery before wider production use. Never solve capacity limits by publishing unprotected media.

Delete actions soft-delete records and immediately revoke access. Originals are retained, in-flight jobs cannot restore visibility, and later queued jobs skip deleted records. After verifying backups, an operator can explicitly run `php artisan videos:purge --days=30 --force`. It permanently deletes eligible originals/thumbnails under the processing lock. Do not schedule purge until a retention/backup policy has been agreed.

## Tests and checks

```sh
php artisan test
npm run test:js
vendor/bin/pint --test
npm run build
composer check-platform-reqs
```

Tests use an isolated in-memory SQLite DB and a small synthetic video; FFmpeg and FFprobe are required for the real processing test. Coverage includes auth/rate limiting, Filament access, ownership, quotas, multi-chunk integrity, resume, checksum rollback, retry/concurrency locking, expiry, soft deletion, processing, thumbnails, byte ranges, and seeking into a sparse 5 GiB temporary file with a bounded memory assertion. Never point tests at a real library database.

For MariaDB, create a dedicated test DB and run with environment overrides, e.g. `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=homereel_testing DB_USERNAME=... DB_PASSWORD=... php artisan test`. Use a protected environment file or secret manager rather than entering real passwords into shell history.

## Known limitations

Hostpoint CPU quotas, actual web-server timeout behavior, CLI extension availability, Composer usability and server streaming delegation remain staging checks. A real 4–8 GiB upload has not been tested on hosting. The sparse-file test verifies integer/range handling, not transfer throughput. There is no whole-file digest, HLS/adaptive transcoding, antivirus scanning, invitation/reset-email workflow, groups, albums or external provider. Metadata inspection is not a sandbox for untrusted codec parsers: keep FFmpeg patched and restrict upload access. One active remembered upload per browser/account; multiple tabs are protected by offset checks and locks but may require manual resume. No browser background-upload guarantee. Database/filesystem crashes cannot be made one atomic operation: originals are retained for recovery, and DB plus media need coordinated backups.

See [Hostpoint staging deployment](docs/hostpoint-deployment.md) before exposing an instance.
