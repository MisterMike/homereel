# Hostpoint Smart staging deployment

This is an intended runbook, not a claim of an existing Hostpoint connection or deployment. No infrastructure was changed. Start with a dedicated HTTPS staging subdomain and a small group of trusted testers.

## Confirm the hosting contract and runtime

The supplied diagnostic found PHP 8.3.37/64-bit, MariaDB PDO, executable FFmpeg/FFprobe 9.0.1, CLI PHP, Composer, working byte ranges, and successful 8/32 MiB chunks. It did not verify Composer CLI compatibility or permission to spend CPU on FFmpeg. Reconfirm these on the exact staging account; do not assume another subdomain has identical settings.

Over SSH, identify the absolute PHP 8.3+ CLI binary and use it consistently for Composer, Artisan and cron. Check `php -v`, `php --ini`, `php -m`, `php -r 'echo PHP_INT_SIZE;'`, `composer --version`, `ffprobe -version`, and `ffmpeg -version`. PHP_INT_SIZE must be 8. Confirm FPM and CLI versions/extensions separately. Needed extensions: ctype, curl, dom, fileinfo, filter, hash, intl, mbstring, openssl, pcre, PDO, session, tokenizer, XML, zip, and pdo_mysql. CLI pcntl/posix are needed for reliable queue hard timeouts; without them arrange a hosting-approved process time limit and keep jobs very small before enabling processing.

Ask Hostpoint about cron minimum interval, process/job duration limits, concurrent PHP streams, request-body buffering and FFmpeg CPU allowances. Thumbnails default off. No Redis, Supervisor, root access, Docker, server configuration edits or permanent worker is assumed. Confirm the private filesystem supports flock and atomic rename.

## Prepare a release and private data

1. Create a MariaDB database and a dedicated application user. Use Hostpoint's assigned hostname and credentials; choose utf8mb4 and verify InnoDB transactions. Do not reuse unrelated applications' DBs. Back up before migrations.
2. Put the Laravel release outside public web folders, for example an account-private releases directory. Keep shared `.env`, storage/framework, storage/logs and the videos root stable across releases. Use only paths actually available to your account; examples here are placeholders.
3. In Hostpoint's subdomain settings set its document root to the selected release's **public/** directory. Verify it really points there; never make the repository root public. If the control panel cannot target it, resolve that with Hostpoint before proceeding. Do not copy index.php upward or expose application files behind only .htaccess protection.
4. Set VIDEO_STORAGE_ROOT to an absolute directory **outside every public document root**, ideally shared across releases and on the same filesystem for uploads and originals. It holds uploads/, originals/, optimized/, thumbnails/ and locks/. Do not run storage:link. Test that no URL maps to any of these paths.
5. Make storage/ and bootstrap/cache/ writable by the actual PHP/CLI account. Use owner-only 0700 directories and 0600 files for private media and .env where the hosting execution identity allows it; otherwise use narrowly scoped account-group permissions after checking FPM identity. Do not use 0777. Only public assets need web-readable permissions. Never change ownership or permissions recursively across the whole hosting account.
6. Build assets locally or in CI with `npm ci && npm run build`, then ship public/build including manifest.json. Node is not needed on Hostpoint at runtime. Include self-hosted fonts and the local avatar.
7. Install locked production PHP dependencies with the appropriate CLI binary: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`. Run `composer check-platform-reqs --no-dev`. Do not run composer update on hosting. If Composer cannot run there, build vendor in a PHP 8.3-compatible environment and validate platform requirements on the target.

## Production environment

Copy .env.example privately, then configure at least:

```dotenv
APP_NAME=HomeReel
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-staging-subdomain.example
APP_KEY=generate-once-with-artisan
DB_CONNECTION=mysql
DB_HOST=hostpoint-assigned-host
DB_PORT=3306
DB_DATABASE=assigned-database
DB_USERNAME=assigned-user
DB_PASSWORD=private-secret
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
VIDEO_STORAGE_ROOT=/absolute/account-private/homereel/videos
VIDEO_MAX_BYTES=8589934592
VIDEO_CHUNK_BYTES=8388608
VIDEO_STORAGE_LIMIT_BYTES=429496729600
VIDEO_UPLOAD_HOURS=48
FFPROBE_PATH=/verified/path/to/ffprobe
FFMPEG_PATH=/verified/path/to/ffmpeg
VIDEO_THUMBNAILS=false
VIDEO_PROCESS_TIMEOUT=60
VIDEO_OPTIMIZATION_ENABLED=false
VIDEO_OPTIMIZATION_AUTO=false
VIDEO_OPTIMIZATION_TIMEOUT=3600
```

Leave DB_QUEUE_CONNECTION unset so queue insertion shares the application transaction. Replace all placeholders. The default 400 GiB logical allowance leaves nominal headroom within the stated 500 GB hosting allowance, but other account data and backups also consume space; lower it accordingly. Never use the 4G upload_max_filesize as an excuse to send a full file. post_max_size=64M still caps request bodies; use 8 MiB chunks. If the available upload bandwidth or server buffering makes a chunk request take too long, reduce VIDEO_CHUNK_BYTES before creating new sessions (existing sessions retain their negotiated chunk size). Browser hashing only handles one chunk at a time.

Generate APP_KEY only on first setup: `php artisan key:generate`. Retain it securely; replacing it invalidates encrypted sessions and may make encrypted data unreadable. Then:

```sh
php artisan migrate --force
php artisan filament:assets
php artisan app:create-admin
php artisan optimize
```

Use absolute PHP paths in actual commands. Do not seed demo accounts or publish debug tools. HTTPS must be available before enabling secure cookies. When behind a reverse proxy, configure only verified trusted proxies if Laravel sees the wrong scheme; do not trust arbitrary forwarded headers.

## Short-lived queue workers and cleanup

Use one scheduler cron entry with absolute paths. Adapt paths and frequency to Hostpoint's supported cron UI:

```cron
* * * * * cd /absolute/current-release && /absolute/php artisan schedule:run >> /absolute/private/scheduler.log 2>&1
```

`/absolute/php` is a placeholder to verify, not a claimed Hostpoint location. The Laravel scheduler starts independent background workers for `database` and `transcoding` every minute, with separate database-cache overlap locks. It also runs `uploads:cleanup` hourly. The cache must be shared between scheduler invocations, and the host must allow background CLI processes. Worker output goes to private `storage/logs/queue.log` and `storage/logs/transcoding.log`; rotate these and the scheduler log. Remove legacy direct `queue:work` cron entries when switching to this setup so they do not create duplicate workers. Keep the scheduler cron even when no uploads are pending.

--max-time=50 controls whether another job starts; it **does not stop a running job at 50 seconds**. Each ProcessVideo may run two 60-second processes plus overhead; the job/worker timeout is 150 seconds and retry_after is 300. A background worker can therefore exceed one minute even though the scheduler invocation returns promptly. With thumbnails disabled it runs only the probe. If Hostpoint's process allowance is shorter, reduce VIDEO_PROCESS_TIMEOUT and align the job timeout (2 × process timeout + 30), worker timeout and retry_after, or disable queued processing until an approved alternative is available. A too-short timeout safely retains the original but may leave processing incomplete. A killed job is retried after its queue reservation expires; failed jobs are visible with `php artisan queue:failed` and can be retried with `php artisan queue:retry ID` after fixing the cause. The Filament edit page also offers Retry inspection for failed videos.

Do not configure sync queues or after-response processing for video inspection. Do not schedule videos:purge until backup and retention rules are agreed. Explicit purge is irreversible: `php artisan videos:purge --days=30 --force`.

## Optional MP4 conversion: hosting approval required

Keep conversion disabled in **Admin → Video settings** (and `VIDEO_OPTIMIZATION_ENABLED=false` as the initial environment default) until Hostpoint confirms sustained FFmpeg CPU use and long CLI jobs are permitted. Thumbnail permission alone does not establish permission for full-video encoding. A short cron worker cannot divide a conversion into short requests. The default FFmpeg process timeout is 3600 seconds; this may be unsuitable for Smart shared hosting. Test a representative clip with the host's approval first. Originals are internal only; with conversion disabled, existing web copies remain playable, but new uploads require a web copy before playback.

The database settings take precedence after the first admin save, so changing environment defaults alone does not disable a previously enabled site. If approved, enable optimization in **Admin → Video settings** and optionally enable automatic conversion after inspection. The scheduler above already starts the separate conversion worker. For a manual diagnostic run from the application directory:

```sh
/absolute/php artisan queue:work transcoding --queue=standard,transcoding --stop-when-empty --max-time=50 --tries=1 --timeout=3690
```

The worker checks the `standard` queue before `transcoding` so the first playable copy takes priority over waiting HD jobs. Mail is handled by the ordinary worker. Existing quality settings now control optional HD: choose 1080p or higher with a suitable bitrate for a second version. Standard is capped at 720p / 2500 kbit/s and remains available if HD fails. Existing videos can be prepared with **Web-Version erstellen** on their edit page.

The dedicated `transcoding` connection uses the same database/table but a reservation timeout of `VIDEO_OPTIMIZATION_TIMEOUT + 180`, greater than the job timeout of `VIDEO_OPTIMIZATION_TIMEOUT + 90`. Adjust the worker timeout if changing this setting. Never run this queue through the ordinary `database` connection with its short retry_after. `--max-time=50` does not interrupt encoding. Enforce job timeouts with CLI pcntl/posix, monitor failures, rotate private logs, and allow space for the original plus its output reservation. The original remains intact internally during and after failed conversion; any existing web copy remains playable. Only verified web copies appear in the player. After a killed worker or host restart, a later worker must process expired reservations to release space and remove incomplete output. Do not manually delete active conversion files.

Before wider staging use, test conversion failure, storage exhaustion, Standard/HD seeking, readiness emails, and permission checks for both variants. Verify backups include `web/`, `optimized/` and database metadata. Existing originals remain the authoritative copies.

## Release and rollback

Keep versioned releases, the previous artifact and locked dependencies. Back up MariaDB and private media consistently, including incomplete uploads if resumption must survive restoration. Storage and APP_KEY must remain stable through a release switch. Pause cron, let current jobs/streams finish, use maintenance mode as needed, install/build the candidate, migrate forward, publish Filament assets and refresh caches, switch the document root/current release using facilities actually available, smoke test, then restore cron. No automatic remote symlink or server configuration workflow is assumed here.

For rollback, restore the previous code/artifact and clear/rebuild caches; assess schema compatibility before rolling back migrations. Never run migrate:fresh or destructive down migrations against the family library. Restore matched DB/media backups if data restoration is necessary. An original moved before an unsuccessful DB commit is intentionally retained; uploads:cleanup reports the upload ID for manual recovery. Logs and failed jobs may contain private paths and exception details: restrict access and retention.

## Before enabling wider access

- [ ] Verify exact PHP/CLI extensions, Composer platform check, FFprobe and queue timeouts.
- [ ] Confirm Hostpoint permits intended CPU load; leave thumbnails disabled until then.
- [ ] Confirm HTTPS, secure/HttpOnly/SameSite cookies, APP_DEBUG=false and correct APP_URL.
- [ ] Check /.env, /composer.json, /storage/, /vendor/ and private media paths return 403/404; directory listing is off. A random video URL alone is never authorization.
- [ ] Create one administrator interactively and one normal prototype user with a strong password. No public registration.
- [ ] Verify guests cannot reach media/thumbnails and normal users cannot reach admin or upload endpoints. Verify hidden videos stay hidden and available videos work for members.
- [ ] Upload the small fixture, run cron worker, inspect metadata, and opt into thumbnail generation only after CPU approval. Confirm no original is deleted on a failure.
- [ ] Interrupt a multi-chunk upload, reload, reselect the same file, resume and compare an offline checksum of the assembled original on staging.
- [ ] Manually upload a real 4–8 GiB file, verify confirmed offsets and final size, and seek near its end on phone/tablet/desktop. Monitor memory, disk usage, logs and per-request duration. Do not perform a multi-GiB hash during HTTP finalization.
- [ ] Check GET, HEAD, bytes=0-1023, a suffix range and an unsatisfiable range with an authenticated client. Expect correct 200/206/416 and byte counts.
- [ ] Test sustained playback with intended concurrency and FPM timeouts. PHP streaming holds a worker per stream: it is only a small-scale proof of concept. X-Sendfile and X-Accel were not confirmed. Arrange authorized server delegation or a signed streaming provider before broader production traffic; never publish originals as a workaround.
- [ ] Verify cron non-overlap, expired-upload cleanup, private log rotation, storage headroom, backups and a practiced restore/rollback.
