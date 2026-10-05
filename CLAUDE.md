# Logger API

Part of the **guia.lol** project. A flexible logging microservice where different services authenticate via JWT and log arbitrary JSON data, each isolated to their own logs. Designed to run as several independent instances (own DB, `magrathea.conf`, `config.json`, vhost each) — nothing is hardcoded to guia.lol hosts or paths.

## Stack

- PHP 8.4 + MagratheaPHP2 2.3.3 (same layout as `api/`; read the `magrathea-php2` skill before writing PHP)
- MariaDB — one table, `logs` (`database/logs.sql`)
- JWT (firebase/php-jwt, HS256)
- Apache (`src/app/.htaccess`) or Caddy (`site.caddy.example`) in prod; Docker only for local testing
- No tests yet

## Project Structure

```
config.json                    Service credentials (userId + secret [+ readonly]); outside the docroot
database/logs.sql              CREATE TABLE logs — run per instance DB
site.caddy.example             Prod Caddy config (placeholders)
cors-origins.json              CORS allowlist (used by src-node; not yet read by the PHP app)
version, changelog.md          Release version + notes
scripts/                       reboot/run/erase (local), configure (config.json), deploy + restart (prod update)
src/
  composer.json
  configs/magrathea.conf       Gitignored; copy from .example. [dev] reads .env, [production] is filled in per instance
  app/                         ← DOCROOT
    .htaccess                  Prod Apache config: routing, static pages, basic auth on /admin*, headers
    _inc.php, index.php        Bootstrap + entry point (namespace `logger`)
    api/LoggerApi.php          Routes (extends MagratheaApi)
    api/Authentication/LoggerAuth.php   Login, IsService (base auth), raw-secret jwtEncode/jwtDecode
    api/Controls/LogApi.php    POST /log, POST /error, GET /report
    api/Controls/AdminApi.php  GET /admin/logs, GET /admin/services
    features/Log/              Log model (Base/ is generator-style) + LogControl (validated read queries)
    shared/ServiceUsers.php    Reads ../../config.json
    admin.html|js|css          Static dashboard; docs.html + openapi.yaml for /docs
docker/                        Local-only Dockerfile + Apache vhost
docker-compose.yml             Local-only: logger_php + logger_db (mariadb)
src-node/                      Archived Node version (last: 1.1.2, MariaDB) — reference only, not deployed
```

## Key Design Decisions

- **Users live in `config.json`**, not the database — read on every request; no restart needed.
  Entries may use `userId` or the legacy `service` key. `"readonly": true` → 403 on writes.
- **Removing a service from `config.json` revokes its tokens** — `IsService` checks it still exists.
- **`data` is a JSON column** — each service logs whatever structure it wants.
- **`userId` comes from the JWT only**, never from the request body.
- **JWTs never expire** and are `{userId, iat}` HS256, signed with the **raw** `jwt_key` (the vendor
  `jwtEncode`/`jwtDecode` mangle `-`/`_` in the secret; overridden so Node-issued tokens still verify).
  `jwt_key` must be ≥ 32 bytes (php-jwt v7).
- **Responses use the Magrathea envelope** `{success, data}`; errors carry the real HTTP status
  (`LoggerApi::ReturnError` fixes the vendor's 200-on-404). `/log` returns 200, not 201.
- **Ids are UUIDv7** (`uuid` field type), inserted with `InsertWithPk()` — plain `Insert()` would overwrite the id.
- **Timestamps are UTC `DATETIME(3)`**, returned as ISO-8601 `…Z`.
- **Read queries validate every input** (userId regex, dates via `DateTimeImmutable`, limit 1–1000, skip ≥ 0) before building SQL.
- **Admin is open at the PHP level**; the web server puts basic auth on `/admin*`.
- Magrathea lower-cases column names in results — use snake_case SQL aliases.
- If `logs_path` isn't writable, every API error becomes an HTML error page (Magrathea logs exceptions there).

## Running (local)

```bash
./scripts/reboot.sh            # docker compose down/up --build, waits for /health
./scripts/run.sh               # up --build + follow logs
./scripts/erase.sh [section]   # TRUNCATE logs, creds from magrathea.conf
(cd src && composer install)   # vendor/ is gitignored
```

## API Endpoints

| Method | Path            | Auth          | Purpose |
|--------|-----------------|---------------|---------|
| POST   | /login          | No            | Get JWT token (userId + secret) |
| POST   | /log            | Bearer        | Ingest any JSON payload |
| POST   | /error          | Bearer        | Same, with `level: "error"` unless set |
| GET    | /report         | Bearer        | Own logs (from, to, limit, skip) |
| GET    | /health         | No            | `{status, database}` |
| GET    | /admin          | Basic (web server) | Admin dashboard |
| GET    | /admin/logs     | Basic (web server) | All logs (userId, from, to, limit, skip) |
| GET    | /admin/services | Basic (web server) | Per-service counts + last activity |
| GET    | /docs           | No            | Swagger UI (renders openapi.yaml) |
| GET    | /help           | No            | Redirects to /docs |

## Notes

- `deploy.md` is the per-instance deploy guide (guia.lol prod is the worked example).
- `blueprint.md` contains the original (Node) specification and build history.
- Known clients: crawler (`POST /log`), api's `LoggerService` (`POST /log`, `POST /error`).
- **The PHP API is not yet at parity with the Node 1.1.x that runs in prod** (archived in `src-node/`):
  the `admin` app uses its `/admin/logs|errors|services` (Bearer, `readonly` credential), the `DELETE`
  purge routes and `/health-check`. Port those before switching prod to PHP — see `todo`.
