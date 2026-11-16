### 1.3.0
2026-11
	- **change:** runs on MagratheaPHP 3.0 (was MagratheaPHP2 2.3.3). No API change: same routes, fields, statuses and envelope, and database failures still answer a generic 500
	- **change:** a write that fails in the database answers `Internal server error` (was `Failed to save log` / `Failed to save error`); the status is still 500
	- **change:** CORS responses send `Vary: Origin`
	- **fix:** a failed bootstrap (missing config, unreachable vendor) printed the exception, with paths and config details, into the response; it's now logged and answered with the envelope's generic 500. A PHP error inside a route is answered the same way
	- **fix:** purges report the rows the `DELETE` removed (they were counted by a `SELECT` just before it)
	- **migration:** run `database/migrations/1.3.0-timestamps.sql` once per instance. It adds `created_at` and `updated_at` to both tables: framework bookkeeping, filled by the database and never returned by the API (existing rows get the migration's time). Needs `ALTER`, so run it as a privileged user; it rebuilds both tables, which can block writes on large ones. It works with 1.2.3, so it can run before the deploy
	- **fix:** both migrations (`1.2.2-occurred-at.sql`, `1.3.0-timestamps.sql`) failed with a syntax error on MySQL: `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` are MariaDB-only. They now check `information_schema` before each `ALTER`, so they run on MariaDB and MySQL and stay re-runnable
	- **config:** in each `magrathea.conf`, delete `time_zone` from `[general]` (it was never read; `timezone` in each section is the real key) and add `backups_path`, a writable folder outside the docroot
	- **removed:** `setup.php` (MagratheaPHP2's setup wizard entry, already disabled)
	- **admin:** the vendor admin (`magrathea.php`) has a new Database section (explorer, queries, query editor, dumps, checks); dumps go to `backups_path`. Every admin has to log in again after the deploy, and inactive admin users can no longer log in
	- **fix:** `/admin` dashboard: refresh and auto-refresh didn't show new entries after a 1h / 24h / 7d preset, because the preset fixed `to` at the moment it was clicked. Presets are now rolling: every load recomputes `from` and leaves `to` open. They also apply at once (no need to click apply), and editing from/to by hand turns them off
	- **change:** `/admin` dashboard: the service filter is in the top bar and applies to both logs and errors; it is remembered across reloads. Clicking a service card selects it and opens its logs (it opened errors). The logs and errors tabs reload whenever they are opened
	- **change:** API responses send `Cache-Control: no-store` (they had no cache headers, so browsers could reuse them), and the static pages (`admin.html|js|css`, `docs.html`, `openapi.yaml`) send `Cache-Control: no-cache`, so a deploy shows up without a hard refresh. Apache gets this from `.htaccess`; on Caddy, copy the two `@static` lines from `site.caddy.example` into the server's Caddyfile. Browsers still holding the old dashboard need one hard refresh after this deploy
	- **dev:** `scripts/reboot.sh` waits until `/health-check` reports `"database":"ok"`, not only for a 200, so `./scripts/test.sh` right after it no longer fails while MariaDB starts
	- **dev:** `PurgeTest`; `vendor/bin/magrathea check` and `models:verify` check the config, folders, timezone and both tables

### 1.2.3
2026-10
	- **fix:** `timestamp`, `occurredAt` and `lastLog` were off by the database server's UTC offset when its clock wasn't UTC (and so were `from`/`to` filters and purge cutoffs): the arrival time and the purge cutoff were read from the database's `NOW()`, then labelled UTC. Both now come from PHP's clock, so the database's timezone no longer matters
	- **change:** both columns are stored in Magrathea's timezone (`timezone` in `magrathea.conf`); the API still reads and returns every date in UTC (`…Z`), so clients see no difference. Instances with `timezone = "UTC"` store exactly what they did before
	- **note:** rows written before 1.2.3 on an instance whose database clock wasn't in `timezone` keep the wrong time; shift them by the offset (`UPDATE … SET timestamp = timestamp + INTERVAL …, occurred_at = occurred_at + INTERVAL …`) or purge them

### 1.2.2
2026-10
	- **new:** `POST /log/batch` and `POST /error/batch` — 1–100 entries per request (body ≤ 256 KB, otherwise 413), all or nothing: any invalid entry is a 400 naming it (`entries[3]: must be an object`) and nothing is stored; a database failure stores nothing and returns 500. Response `{count}`
	- **new:** event time — `occurredAt` and `sentAt` are reserved body keys (ISO-8601, UTC without an offset; 400 if invalid) and are no longer stored in `data`. With both, the stored time is `arrival - (sentAt - occurredAt)`, so the client's clock doesn't need to be right; with only `occurredAt` it is kept as sent; never later than the arrival
	- **new:** every entry read back (`/report`, `/errors`, `/admin/logs`, `/admin/errors`) has an `occurredAt`; `from`, `to` and the newest-first order now use it. `timestamp` is still the arrival time, and `lastLog` and purges still use it. Entries without a client time have `occurredAt` = `timestamp`, so existing clients see the same results
	- **change:** `timestamp` is stored with milliseconds, and the `timestamp` returned by `POST /log` / `/error` is now exactly the stored value (one database clock reading per request)
	- **migration:** run `database/migrations/1.2.2-occurred-at.sql` once per instance (adds `occurred_at` to both tables, backfills it from `timestamp`, adds `(service, occurred_at)` indexes; safe to re-run). Needs `ALTER`, so run it as a privileged user
	- **fix:** a database failure during a write could prefix the JSON response with the vendor's stray `got error!` output
	- **dev:** PHPUnit suite (`./scripts/test.sh`, runs inside the container)

### 1.2.1
2026-10
	- **fix:** unexpected server errors (e.g. database failures) returned HTTP 200 with `success: false` and the exception's internals (SQL / connection details) in `data`; they now return 500 with a generic `Internal server error` message and are written to the server log
	- **fix:** `POST /login` and `POST /token` ignored JSON bodies sent as `application/json; charset=utf-8` (401 / 400); the body is now parsed as JSON whatever the `Content-Type`, as in Node 1.x

### 1.2.0
2026-10
	- **breaking:** every response uses the Magrathea envelope `{success, data}`; errors are `{success: false, data: {message, code}}` with the real HTTP status. Field names inside `data` are unchanged
	- **breaking:** successful writes (`POST /log`, `POST /error`) return 200 instead of 201
	- **new:** rewritten in PHP (MagratheaPHP2) on the same `logger_logs` / `logger_errors` tables and the same JWT format: no data migration, and existing tokens keep working when `jwt_key` reuses Node's `JWT_SECRET` (it must be ≥ 32 bytes)
	- **new:** `POST /login` accepts `userId` as an alias of `service`; Mongo-era `{userId}` tokens are still accepted
	- **change:** `readonly` and `active` are read from `config.json` on every request (no restart; the `readonly` claim inside the token is no longer trusted), and removing a service revokes its tokens
	- **change:** `/admin` dashboard: same UI as 1.1.x, adapted to the envelope; no more web-server basic auth on `/admin*`

### 1.1.2
2026-09
	- **new:** `DELETE /admin/logs` and `DELETE /admin/errors` — purge a service's logs/errors older than `olderThanDays` (default 365); each purge writes a self-audit entry into `logger_logs` under the reserved `service: 'logger'`
	- **new:** `GET /admin/services` now includes a synthetic `Logger (self)` entry so the audit trail's service is selectable without a `config.json` credential

### 1.1.1
2026-08
	- **new:** optional `active` flag on `config.json` service credentials — inactive services are blocked from `POST /log` and `POST /error`, and excluded from `GET /admin/services`
	- **fix:** admin dashboard `services` tab no longer crashes on a failed `/admin/services` request

### 1.1.0
2026-08
	- **new:** scoped read-only admin credential + global CORS allowlist, for the guia.lol admin app integration

### 1.0.0
2026-07
	- **new:** Rise and Shine
