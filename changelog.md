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
