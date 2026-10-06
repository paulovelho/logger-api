# Plan: PHP logger at parity with Node 1.1.2 (the version running in prod)

## Context

The PHP rewrite (`src/`, see `plan-php-migration.md`) was built against the old Mongo-era contract. Prod runs
Node 1.1.2 (`src-node/`), and its clients are crawler, api, auth, profiles, admin and status. The review on
2026-10-05 found that the PHP version would reject every existing token and is missing several routes.

**Goal:** PHP behaves like Node 1.1.2 does today. The **one intended change** is the response format: every
response uses the Magrathea envelope `{success, data}` (errors: `{success: false, data: {message, code}}`, with
the real HTTP status). The openapi spec, the skill and the changelog document this as the breaking change (v1.2.0).

**Decisions already made**
- **Envelope everywhere.** The writers (crawler, api, auth, profiles) are fire-and-forget and ignore the body.
  The `admin` app is the only client that reads responses; Paulo fixes it separately.
- **Storage = prod's schema.** PHP reads and writes the existing `logger_logs` / `logger_errors` tables (BIGINT
  auto-increment id, `service`, `environment`, `data` JSON, `timestamp DATETIME DEFAULT CURRENT_TIMESTAMP`).
  Cutover needs no data migration: the PHP app points at the same database (`guia_lol` in prod). The UUIDv7 /
  `DATETIME(3)` `logs` table is dropped.
- Inside the envelope, field names stay as they are today (`_id`, `service`, `serviceName`, `environment`,
  `data`, `timestamp`; `total/count/logs|errors`; `services`; `deleted`; `token`). This way the admin fix is
  only "read `.data`".

## Contract (Node 1.1.2 behaviour, envelope applied)

| Method | Path | Auth | `data` on success | Node source |
|---|---|---|---|---|
| POST | `/login` | – | `{token}` | `routes/auth.js` |
| POST | `/token` | – | `{decoded}`; bad token → 400 | `routes/token.js` |
| POST | `/log` | Bearer, active | `{id, timestamp}` | `routes/log.js` |
| POST | `/error` | Bearer, active | `{id, timestamp}` | `routes/error.js` |
| GET | `/report` | Bearer | `{total, count, logs}` | `routes/report.js` |
| GET | `/errors` | Bearer | `{total, count, errors}` | `routes/errors.js` |
| GET | `/admin/logs` | Bearer, readonly | `{total, count, logs}` (+ `service` filter) | `routes/admin.js` |
| GET | `/admin/errors` | Bearer, readonly | `{total, count, errors}` (+ `service` filter) | `routes/admin.js` |
| GET | `/admin/services` | Bearer, readonly | `{services: [{service, name, count, lastLog}]}` | `routes/admin.js` |
| DELETE | `/admin/logs` | Bearer, readonly | `{deleted}` (`service` required, `olderThanDays` default 365) | `routes/admin.js` |
| DELETE | `/admin/errors` | Bearer, readonly | `{deleted}` (same) | `routes/admin.js` |
| GET | `/health-check` | – | `{health, time, database}` (already enveloped in Node) | `index.js` |
| GET | `/version` | – | `{version}` (already enveloped in Node) | `index.js` |
| GET | `/admin`, `/docs`, `/help`, `/openapi.yaml` | – | static | `index.js` |

Status codes: success is always 200, because Magrathea's `Json()` sends 200 (Node sent 201 on `/log` and `/error`).
No client checks for 201: auth only checks `res.ok`.

## Implementation

### 1. Schema: `database/`
- Delete `database/logs.sql`.
- Add `database/schema.sql`, a copy of `src-node/database/schema.sql` (`CREATE TABLE IF NOT EXISTS`, so it is safe
  to run against prod). Local docker-compose still runs `./database` as initdb.

### 2. Models: features `Log` and `ErrorLog`
- `features/Log/Base/LogBase.php` uses `dbTable = "logger_logs"`. Fields: `id` int, `service` string,
  `environment` string, `data` text, `timestamp` datetime. Keep the `$created_at, $updated_at` declarations
  (CreateInsertQuery assigns them).
- `features/ErrorLog/…` is the same, with `dbTable = "logger_errors"`. Add `AddFeature("ErrorLog")` in `_inc.php`.
- Writes use plain `Insert()` (auto-increment; returns `insert_id`). `timestamp` is left unset, so the DB default
  fills it, exactly like Node. The response `timestamp` is "now" in ISO-8601 UTC, like Node's `new Date()`.
- `Log::Write(string $table, string $service, array $body)` (or one static method per model): pull
  `environment` out of the body (default `'unknown'`), JSON-encode the rest into `data`. As in Node, an empty
  `{}` body is accepted and stored. Only a non-object body (JSON array or scalar) is rejected with 400.

### 3. Reads: `LogControl` (shared by both tables)
- `Search(string $table, ?string $service, array $query)` → `{total, count, <key>}`. `<key>` is `logs` or `errors`.
- Keep the current validation, with one change: the service regex widens to `^[A-Za-z0-9_.-]{1,100}$` to match
  `VARCHAR(100)`. Prod names such as `guia.lol-staging-api` already pass. `limit` 1–1000, default 100
  (largest page any UI asks for is 250); `skip` ≥ 0; `from`/`to` parsed with `DateTimeImmutable`.
- Order by `timestamp DESC, id DESC`.
- Row shape: `{_id: int, service, serviceName, environment, data: decoded, timestamp: ISO-8601 Z}`.
- `Services()` reproduces Node's `/admin/services`:
  - every **active** service from `config.json` with its `name` (falls back to the id), including services
    with 0 logs;
  - plus the synthetic `{service: "logger", name: "Logger (self)"}`;
  - counts and `lastLog` come from `logger_logs` only, as in Node;
  - sorted by `lastLog` descending, `null` last.
- `Purge(string $table, string $service, int $days, string $performedBy)`:
  - runs `DELETE … WHERE service = ? AND timestamp < NOW() - INTERVAL ? DAY` with a prepared statement and
    returns the affected rows. Check that `PrepareAndExecute` exposes `affected_rows`; if not, use `COUNT(*)`
    and then `DELETE` inside the same request.
  - then inserts the self-audit row into `logger_logs`: `service: 'logger'`, `environment: 'unknown'`,
    `data: {action: purge_logs|purge_errors, targetService, cutoffDays, deletedCount, performedBy}`.
  - `service` missing → 400 `service is required`; `olderThanDays` ≤ 0 or not numeric → 400.

### 4. Auth: `LoggerAuth` + `ServiceUsers`
- **Tokens.** `Login` accepts `{service, secret}`, with `userId` still accepted as an alias. It issues
  `{service, readonly, iat}` exactly like Node, signed HS256 with the raw secret, no `exp`.
- **`IsService`** reads `service` from the token. It falls back to the `userId` claim for Mongo-era tokens.
  The service must still exist in `config.json`, so removing it revokes its tokens. On writes, Node also
  required the service to exist (`require-active.js`).
- **`IsActive`** (route auth for `/log` and `/error`) = `IsService` + the `config.json` entry is not
  `active: false`. Otherwise 403 `Forbidden`.
- **`IsAdmin`** (route auth for `/admin/*` API) = `IsService` + `readonly: true` in `config.json`. Otherwise
  403 `Forbidden`. PHP reads `readonly` from `config.json` on every request. Node read it from the token
  claim, which went stale whenever the config changed.
- **`readonly` semantics change back to Node's.** `readonly` means "admin credential". It no longer blocks
  writes, so `WritableService()` and its 403 go away.
- `ServiceUsers` gains `IsActive()`, `Name()` and `All()`. The `jwt_key` ≥ 32-byte check stays, and so do the
  401 mappings for decode errors.
- `POST /token`: verify with the same key and return `{decoded}`; on failure, 400 with the php-jwt message
  (Node returned `err.message`).

### 5. Routes: `LoggerApi`
- CORS: `Allow(<cors-origins.json>)` replaces `AllowAll()`. The file is read from the module root, next to
  `config.json`. A missing or invalid file means no `Access-Control-Allow-Origin` header, and servers are
  unaffected. The vendor preflight (`getMethod`) already answers `OPTIONS`.
- Remove `GET /health`. Use the vendor `HealthCheck(true)` for `/health-check`. Add `GET /version`, which reads
  `../../version` and returns `{version}`.
- Keep the `ReturnError` status fix.
- Route table as above.

### 6. Web server configs
- `.htaccess` and `site.caddy.example`: **remove basic auth on `/admin*`.** The admin API is now protected by
  Bearer + `readonly`, as in Node. Basic auth would also block the admin app's cross-origin Bearer calls and
  their CORS preflights.
  - `admin.html` is public, as it was in Node. It is only a shell with a login overlay.
- Keep the static rewrites (`/admin`, `/docs`, `/help`) and the security headers.
- `deploy.md`: drop the htpasswd steps.

### 7. Dashboard: `src/app/admin.{html,js,css}`
- Replace the minimal PHP dashboard with Node's (`src-node/public/`). It has a login overlay that keeps the
  token in localStorage, Logs / Errors / Services tabs, a service filter, date range, paging and auto-refresh.
- Adapt it to the envelope: read `json.data`, and show `json.data.message` when `success` is false.
- No purge UI: Node's dashboard had none, and purging lives in the `admin` app.

### 8. Docs
- `src/app/openapi.yaml`: rewrite from `src-node/openapi.yaml`. Every response schema is wrapped in the
  envelope, plus a shared `Error` envelope schema. Document `bearerAuth` and the per-route conditions
  (active / readonly).
- `.claude/skills/logger-api/SKILL.md`: rewrite it for the contract above. Cover:
  - base URL `https://logger.guia.lol`;
  - `{service, secret}` login; tokens never expire;
  - the envelope, including errors;
  - `environment`;
  - `/error` and `/errors`;
  - the admin routes with a readonly credential;
  - purge;
  - `/health-check` and `/version`;
  - `config.json` flags (`name`, `readonly`, `active`), read live, no restart;
  - Node and PHP examples that read `.data`.
- `changelog.md`: add **1.2.0** (PHP + MagratheaPHP2; Magrathea envelope on every response; success status 200;
  `userId` accepted as an alias). Fix 1.1.2's "default 7" to 365. Set `version` to `1.2.0`.
- `logger/CLAUDE.md`, `README.md`, `deploy.md`: tables, auth model, no basic auth, the clients list (crawler,
  api, auth, profiles → `/error`, admin, status), and the cutover steps below.
- Root `CLAUDE.md`: the logger section and diagram (`logger_logs` / `logger_errors`, more callers).
- `src-node/README.md`: drop the "logs went to a different table" line.
- `todo`: remove what this plan does. Keep the admin-app fix as a cross-module note in `docker/todo`.
- `plan-php-migration.md` → `.claude/done/`, superseded by this plan.

### 9. Scripts
- `erase.sh`: `TRUNCATE logger_logs; TRUNCATE logger_errors`.
- `reboot.sh`: wait on `/health-check`.

## Prod cutover (Paulo; documented in deploy.md)
1. **JWT secret.** Reuse Node's `JWT_SECRET` as `jwt_key` so every issued token keeps working. php-jwt needs
   ≥ 32 bytes: `awk -F= '/^JWT_SECRET=/{print length($2)}' .env`. If it's shorter, every client must log in
   again (crawler, api, auth, profiles `LOGGER_TOKEN` / `logger_token`) before or at cutover.
2. **DB timezone.** Confirm `SELECT @@global.time_zone, @@system_time_zone` is UTC. The DB fills `timestamp`,
   and PHP labels it `Z`. If it isn't UTC, set `time_zone = '+00:00'` on the PHP connection, or convert in
   `IsoDate`.
3. `magrathea.conf` `[production]` → `guia_lol` with the same DB user as Node. Tables already exist; running
   `database/schema.sql` is a no-op.
4. Switch the vhost from the Node proxy to PHP, then stop the Node container.
5. Smoke test: `/health-check`, `/version`, `POST /log` with crawler's existing token, admin login.

## Verification (local Docker)
1. `reboot.sh`. Then `/health-check` → `{success:true,data:{health:"ok",database:"ok",time}}`, and `/version`
   → `1.2.0`.
2. Old tokens:
   - a hand-built HS256 `{service:"service-website", readonly:false, iat}` is accepted;
   - a legacy `{userId}` token is accepted;
   - a token for a service removed from config gets 401.
3. `POST /login` with `{service, secret}` and with `{userId, secret}` returns a token. A wrong secret gets 401.
4. `POST /log` with `{"environment":"production","a":1}`: the row lands in `logger_logs` with
   `environment = production` and `data = {"a":1}`. `POST /error` lands in `logger_errors`. An `active: false`
   service gets 403.
5. `/report` and `/errors` return only the caller's rows, in Node's row shape, inside the envelope.
6. Admin routes:
   - a non-readonly token gets 403; the `guia-admin` (readonly) token gets 200;
   - the `service` filter and paging work;
   - `/admin/services` lists configured active services with 0 counts, plus `Logger (self)`.
7. Purge:
   - `DELETE /admin/logs?service=x&olderThanDays=1` deletes only older rows of `x` and writes the `logger` audit row;
   - no `service` gets 400; `olderThanDays=0` gets 400.
8. CORS:
   - `Origin: http://localhost:4200` gets `Access-Control-Allow-Origin` back;
   - an unlisted origin gets none;
   - `OPTIONS` preflight with `Authorization` works on `/admin/logs` (no basic auth in the way).
9. The `/admin` dashboard logs in with the readonly credential, and the tabs, filters and paging work. `/docs`
   renders the new spec.
10. Optional: load a `mysqldump` of prod's two tables locally and check that `/admin/logs` renders old rows
    (timestamps, `_id`).

## Out of scope
- The `admin` app change (read `.data`). Paulo does it.
- Client changes: none needed. The writers ignore the body, and status already reads `data.*`.
