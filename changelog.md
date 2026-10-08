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
