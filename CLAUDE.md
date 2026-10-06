# Logger API

Part of the **guia.lol** project. A flexible logging microservice where different services authenticate via JWT and log arbitrary JSON data, each isolated to their own logs. Designed to run as several independent instances (own DB, `magrathea.conf`, `config.json`, vhost each) — nothing is hardcoded to guia.lol hosts or paths.

## Stack

- PHP 8.4 + MagratheaPHP2 2.3.3 (same layout as `api/`; read the `magrathea-php2` skill before writing PHP)
- MariaDB — `logger_logs` + `logger_errors` (`database/schema.sql`), the same tables Node 1.1.x used
- JWT (firebase/php-jwt, HS256)
- Apache (`src/app/.htaccess`) or Caddy (`site.caddy.example`) in prod; Docker only for local testing
- No tests yet

## Project Structure

```
config.json                    Service credentials (service + secret [+ name, readonly, active]); outside the docroot
cors-origins.json              CORS allowlist, read per request (LoggerApi::Cors)
database/schema.sql            CREATE TABLE IF NOT EXISTS logger_logs, logger_errors — run per instance DB
site.caddy.example             Prod Caddy config (placeholders)
version, changelog.md          Release version + notes
scripts/                       reboot/run/erase (local), configure (config.json), deploy + restart (prod update)
src/
  composer.json
  configs/magrathea.conf       Gitignored; copy from .example. [dev] reads .env, [production] is filled in per instance
  app/                         ← DOCROOT
    .htaccess                  Prod Apache config: routing, static pages, security headers / CSP
    _inc.php, index.php        Bootstrap + entry point (namespace `logger`)
    api/LoggerApi.php          Routes (extends MagratheaApi), CORS, /health-check, /version
    api/Authentication/LoggerAuth.php   Login, Token, IsService / IsActive / IsAdmin, raw-secret jwtEncode/jwtDecode
    api/Controls/LogApi.php    POST /log, POST /error, GET /report, GET /errors
    api/Controls/AdminApi.php  GET /admin/logs|errors|services, DELETE /admin/logs|errors (purge)
    features/Log/              Log model (logger_logs; Base/ is generator-style) + LogControl (writes, validated reads, purge — both tables)
    features/ErrorLog/         ErrorLog model (logger_errors)
    shared/ServiceUsers.php    Reads ../../config.json
    admin.html|js|css          Dashboard (Node's UI, reads the envelope); docs.html + openapi.yaml for /docs
docker/                        Local-only Dockerfile + Apache vhost
docker-compose.yml             Local-only: logger_php + logger_db (mariadb)
src-node/                      Archived Node version (last: 1.1.2, same tables) — reference only, not deployed
```

## Key Design Decisions

- **Contract = Node 1.1.2's, wrapped in the envelope.** Same routes, field names (`_id`, `service`,
  `serviceName`, `environment`, `data`, `timestamp`; `total/count/logs|errors`) and tables. The only
  intended difference is the response format (see below) — keep it that way; clients depend on it.
- **Users live in `config.json`**, not the database — read on every request; no restart needed.
  Entries use `service` (or the legacy `userId` key). Flags: `name`, `readonly` (= admin credential,
  may call `/admin/*`; it can still write), `active: false` (403 on writes, hidden from `/admin/services`).
- **Route auth** (`LoggerAuth`): `IsService` (valid token + service still in config.json — removing it
  revokes its tokens), `IsActive` (writes), `IsAdmin` (`/admin/*`; `readonly` read from config.json,
  not the token claim).
- **`service` comes from the JWT only**, never from the request body.
- **JWTs never expire** and are `{service, readonly, iat}` HS256 (Mongo-era `{userId, iat}` still
  accepted), signed with the **raw** `jwt_key` (the vendor `jwtEncode`/`jwtDecode` mangle `-`/`_` in
  the secret; overridden so Node-issued tokens still verify). `jwt_key` must be ≥ 32 bytes (php-jwt v7).
- **Responses use the Magrathea envelope** `{success, data}`; errors carry the real HTTP status
  (`LoggerApi::ReturnError` fixes the vendor's 200-on-404). `/log` and `/error` return 200, not 201.
- **Writes**: `environment` (default `unknown`) goes to its own column, the rest of the body to `data`
  (decoded as objects so `{}` stays `{}`). Auto-increment ids via plain `Insert()`; `timestamp` is left
  to the DB default (UTC server time), returned as ISO-8601 `…Z`.
- **`PrepareAndExecute()` returns no affected-row count** and swallows statement errors (returns null),
  so purge counts then deletes against one cutoff, and `Write()` treats a null id as a 500.
- **Read queries validate every input** (service regex, dates via `DateTimeImmutable`, limit 1–1000, skip ≥ 0) before building SQL.
- **Purge** writes an audit row into `logger_logs` under the reserved `service: 'logger'`
  (listed as `Logger (self)` in `/admin/services`).
- **No basic auth on `/admin*`** — the admin API is Bearer + readonly, and the admin app calls it
  cross-origin (CORS from `cors-origins.json`). `/admin` (dashboard) is a public shell with a login form.
- Magrathea lower-cases column names in results — use snake_case SQL aliases.
- If `logs_path` isn't writable, every API error becomes an HTML error page (Magrathea logs exceptions there).

## Running (local)

```bash
./scripts/reboot.sh            # docker compose down/up --build, waits for /health-check
./scripts/run.sh               # up --build + follow logs
./scripts/erase.sh [section]   # TRUNCATE both tables, creds from magrathea.conf
(cd src && composer install)   # vendor/ is gitignored
```

## API Endpoints

| Method | Path            | Auth          | Purpose |
|--------|-----------------|---------------|---------|
| POST   | /login          | No            | Get JWT token (service + secret; `userId` alias) |
| POST   | /token          | No            | Decode a token → `{decoded}` |
| POST   | /log            | Bearer, active | Ingest any JSON object into `logger_logs` |
| POST   | /error          | Bearer, active | Same, into `logger_errors` |
| GET    | /report         | Bearer        | Own logs (from, to, limit, skip) |
| GET    | /errors         | Bearer        | Own errors (same params) |
| GET    | /admin/logs     | Bearer, readonly | All logs (+ `service` filter) |
| GET    | /admin/errors   | Bearer, readonly | All errors (+ `service` filter) |
| GET    | /admin/services | Bearer, readonly | Active services + `Logger (self)`, count + lastLog |
| DELETE | /admin/logs     | Bearer, readonly | Purge (`service` required, `olderThanDays` default 365) |
| DELETE | /admin/errors   | Bearer, readonly | Same, on errors |
| GET    | /health-check   | No            | `{health, time, database}` (vendor `HealthCheck(true)`) |
| GET    | /version        | No            | `{version}` from `version` |
| GET    | /admin          | No (login form) | Admin dashboard |
| GET    | /docs           | No            | Swagger UI (renders openapi.yaml) |
| GET    | /help           | No            | Redirects to /docs |

## Notes

- `deploy.md` is the per-instance deploy guide (guia.lol prod is the worked example).
- `blueprint.md` contains the original (Node) specification and build history.
- Known clients: crawler, auth, linktree-importer (`POST /log`), api (`POST /log`, `POST /error`),
  profiles (`POST /error`), admin app (`/admin/*`, readonly credential), status (`/health-check`, `/version`).
- The `admin` app must read `.data` from the envelope (1.2.0); the writers ignore the body.
