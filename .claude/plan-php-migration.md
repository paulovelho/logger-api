# Plan: logger → PHP + MagratheaPHP2 + MariaDB

## Context

`logger/` is a Node/Express + MongoDB service that runs **only in production** (`logger.guia.lol`). Logs move out of Mongo into the shared guia.lol MariaDB (`guia_lol` database). The service is rewritten on MagratheaPHP2 2.3.3, copying the `api/` module's layout. Local Docker is used only for testing.

**Decisions already made**
- Storage: MariaDB, table `logs`. Each instance connects to its own database, set in `magrathea.conf`. The table name stays `logs` because instances don't share a database.
- Old Mongo logs are **not** migrated. The new table starts empty.
- Responses: the Magrathea envelope `{success, data}`. `admin.js` and `openapi.yaml` get updated to match.
- Admin: keep the static dashboard (`admin.html`, `admin.js`, `admin.css`). Protection is web-server basic auth on `/admin*`.
- Tokens: keep the no-`exp`, HS256 `{userId, iat}` format so the tokens already held by crawler and api keep working.
- Web server: ship **both** a prod-ready `.htaccess` (Apache) and a `.caddy` example. The logger will run as several instances for different uses, so nothing is hardcoded to guia.lol paths or hosts. Per-instance values live in `magrathea.conf`, `config.json` and the vhost.
- Add `POST /error`, used by api's `LoggerService`.
- Enforce `"readonly": true` in `config.json`.
- Node code is kept for reference and renamed `src/` → `src-node/`, not deleted.

**Known clients (the contract to preserve)**
- `crawler/src/utils/logger.ts`: `POST {LOGGER_URL}/log`, Bearer token, JSON body, fire-and-forget.
- `api/src/app/shared/services/LoggerService.php`: `POST /log` and **`POST /error`**. The Node version has no `/error` route, so those calls 404 today.

**Already done before this plan (keep, or revert if the plan changes)**
- Moved `public/{admin.html,admin.js,admin.css,docs.html}` and `openapi.yaml` into `src/app/` (the new docroot) with `git mv`.
- Created `src/composer.json`, `src/configs/magrathea.conf.example`, `database/logs.sql`, `src/app/_inc.php`, `src/app/index.php` and `src/app/shared/ServiceUsers.php`.

## Target layout

```
logger/
  src-node/                      old Node app (index.js, middleware, models, routes) + package.json,
                                 package-lock.json, Dockerfile; reference only, not deployed
  config.json                    (unchanged location; configure.sh keeps working)
  database/logs.sql              CREATE TABLE logs (id CHAR(36) PK, user_id, data JSON, timestamp DATETIME(3)); run per instance DB
  site.caddy.example             prod Caddy config (placeholders for host/path)
  src/
    composer.json                platypustechnology/magratheaphp2 2.3.3
    configs/magrathea.conf(.example)   [dev] uses $=ENV, [production] placeholders (real file gitignored)
    app/                         ← DOCROOT
      .htaccess                  prod Apache config: static rewrites + Magrathea routing + basic auth on /admin
      _inc.php, index.php
      api/LoggerApi.php                    extends MagratheaApi, routes
      api/Authentication/LoggerAuth.php    extends MagratheaApiControl: Login, IsService, jwt*
      api/Controls/LogApi.php              Create, Error, Report
      api/Controls/AdminApi.php            Logs, Services
      features/Log/{Log.php, LogControl.php, Base/LogBase.php, Base/LogControlBase.php}
      shared/ServiceUsers.php              reads ../../config.json (userId or legacy "service" key, readonly flag)
      admin.html|js|css, docs.html, openapi.yaml
  docker/Dockerfile, docker/apache.conf    local test only (php:8.4-apache + mysqli + composer)
  docker-compose.yml             local only: logger_php + logger_db (mariadb, initdb = database/)
```

## Implementation

1. **Model, feature `Log`** (copy the pattern in `api/src/app/features/Link/Base/LinkBase.php`).
   - `dbTable = "logs"`, `dbPk = "id"`.
   - Fields: `id` as `uuid`, `user_id` as `string`, `data` as `text`, `timestamp` as `datetime`.
   - Insert with `InsertWithPk()`. It auto-fills the UUIDv7 (`MagratheaModel::CreateInsertQuery`), and plain `Insert()` would overwrite the PK with `insert_id`.
   - Set `timestamp` explicitly as UTC `Y-m-d H:i:s.v`.
   - `LogControl` holds the read queries:
     - `Search(?userId, ?from, ?to, limit, skip)` returns `{total, count, logs}`.
     - `Services()` does `GROUP BY user_id` and returns `[{userId, count, lastLog}]`.
     - Inputs are validated before they reach SQL: `userId` must match `^[A-Za-z0-9_.-]{1,64}$`, dates are parsed with `DateTimeImmutable` and re-formatted, `limit` is clamped to 1–1000, `skip` must be ≥0. This is needed because `Query::Clean` is too weak to rely on.
   - Output rows as `{id, userId, data: json_decode, timestamp: ISO-8601 Z}`. Order by `timestamp DESC, id DESC`.

2. **Auth: `LoggerAuth`**
   - `jwtEncode`/`jwtDecode` are overridden to call `Firebase\JWT` with the **raw** secret. The vendor version applies `strtr('-_','+/')` to the secret, which breaks Node-issued tokens if the secret contains `-` or `_`.
   - `jwt_key` shorter than 32 bytes throws a clear 500. php-jwt v7 rejects short HS256 keys, and the local `.env` secret is 30 bytes.
   - Decode errors (`UnexpectedValueException`, `DomainException`, `InvalidArgumentException`) become a `MagratheaApiException` with code 401. This follows the workaround in `api/src/app/api/Authentication/AuthApi.php::jwtDecode`.
   - `Login()`: `ServiceUsers::Validate(userId, secret)`, then return `{token}` with payload `{userId, iat}`.
   - `IsService()`: Bearer token, then `userId` claim, and the service must still exist in `config.json`. This makes revocation possible by removing a service. The id and the `readonly` flag are stored on the instance for `LogApi`.
   - `ServiceUsers` gains `IsReadonly(userId)`.

3. **Routes: `LoggerApi`** (copy the pattern in `api/src/app/api/GuiaLolApi.php`)
   - `AllowAll()` CORS. Services call it server-to-server, and the admin is same-origin.
   - `BaseAuthorization($auth, "IsService")`.
   - Routes:
     - `POST login` (public)
     - `POST log` (IsService; readonly → 403)
     - `POST error` (IsService; readonly → 403): same as `log`, but stores `data.level = "error"` when the body has no `level`
     - `GET report` (IsService)
     - `GET health` (public, `{status:"ok", database}`)
     - `GET admin/logs` and `GET admin/services` (public at the PHP level; basic auth comes from the web server)
   - `LogApi::Create` takes `GetPost()`. A non-array or empty body returns 400. The JSON is stored and the response is `{id, timestamp}`. Note: HTTP 200, not 201, because Magrathea's `Json()` always sends 200 on success.

4. **Web server configs (both prod-ready)**
   - `src/app/.htaccess` is the Apache config and also what the local Docker uses.
   - `site.caddy.example` copies `api/site.caddy`: `<host>` and `<path>/src/app` placeholders, hidden-file 403, `php_fastcgi` with `HTTP_AUTHORIZATION`, the same static rewrites, Magrathea rewrite rules, `basicauth` on `/admin*`, and security headers.

   `.htaccess` contents (start from `api/src/app/.htaccess` and the user's `htaccess.example`):
   - `^admin/?$` → `admin.html`, `^docs/?$` → `docs.html`, `^help/?$` → 302 `/docs`.
   - Existing files are served directly. Everything else goes through the `index.php?magrathea_control=…` rules.
   - Pass `SetEnvIf Authorization`.
   - Basic auth on `/admin*` wrapped in `<IfFile /etc/apache2/.htpasswd-logger>`, so it applies in prod and is skipped locally.
   - Security headers moved over from `htaccess.example`.

5. **Static UI**: `admin.js` reads `json.data.services`, `json.data.logs` and `json.data.total`, and checks `success`. `docs.html` is unchanged.

6. **`openapi.yaml`**: rewrite the response schemas to the envelope. `_id` becomes `id`, `/log` returns 200, and `/error` and `/admin/*` are documented.

7. **Local Docker test environment**
   - `docker/Dockerfile` is a slimmed copy of `guia.lol/docker/Dockerfile` (php:8.4-apache, mysqli, rewrite, headers, composer).
   - `docker-compose.yml` replaces the Node+Mongo setup:
     - `logger_php` mounts `./` at `/var/www/logger`, with docroot `src/app`, on port 3002.
     - `logger_db` runs mariadb with `./database` as initdb.
   - `.env.example` gets `JWT_SECRET` (≥32), `DB_HOST/DB_NAME/DB_USER/DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`.

8. **Archive Node in `src-node/`**
   - `git mv` `src/index.js`, `src/middleware`, `src/models` and `src/routes` into `src-node/`. These sit next to the new PHP files in `src/` right now.
   - Also move `package.json`, `package-lock.json` and the Node `Dockerfile` there, so the module root is PHP-only.
   - Fix `package.json` `main`/scripts to `index.js`.
   - Add a short `src-node/README.md` saying it's reference only. Its static paths point at the old `public/`, and the dashboard now expects the PHP envelope.
   - Delete root `node_modules/` (untracked).
   - Leave `swagger.yaml` as is.

9. **Scripts and docs**
   - `.gitignore`: add `src/vendor`, `src/configs/magrathea.conf`, `logs/`, `cache/`.
   - `.dockerignore`: update for the new layout.
   - `run.sh` / `reboot.sh` become local docker compose helpers.
   - `erase.sh` becomes `TRUNCATE logs` through `mysql`, using `magrathea.conf` credentials or an argument.
   - Leave `configure.sh` as is.
   - `htaccess.example` gets folded into the real `src/app/.htaccess` and deleted.
   - `magrathea.conf.example` `[production]` uses placeholders, not guia.lol paths.
   - Rewrite `deploy.md` as a per-instance guide: clone, `composer install --no-dev` in `src/`, create `magrathea.conf` and `config.json`, run `database/logs.sql` against the instance DB, then Apache **or** Caddy with htpasswd/basicauth, then cutover. guia.lol prod is the worked example.
   - Update `logger/CLAUDE.md`, `README.md` and the root `CLAUDE.md` (logger stack, MongoDB row, diagram).

## Prod cutover (the user does this; documented in deploy.md)

1. On the server: `awk -F= '/^JWT_SECRET=/{print length($2)}' .env`.
   - **≥32**: reuse it as `jwt_key`, and the existing crawler and api tokens keep working.
   - **<32**: generate a new secret, then re-login crawler and api and update `LOGGER_TOKEN` and `logger_token`.
2. Create the instance's database and run `database/logs.sql` against it. Then deploy, switch the vhost from the Node proxy to PHP, `docker compose down` the Node+Mongo stack and remove the `mongo_data` volume. Old logs are discarded.

## Verification (local)

1. `docker compose up -d --build`, then `composer install` inside `logger_php`.
2. `GET /health` returns `{success:true,data:{status:"ok",database:"ok"}}`.
3. `POST /login` with a `config.json` service returns a token. A wrong secret returns 401.
4. `POST /log` with Bearer and a JSON body returns `{id,timestamp}`, and the row is visible in MariaDB. With no token, a bad token, or an empty body it returns 401/401/400.
   - `POST /error` stores `level: "error"`.
   - A `readonly` service gets 403 on both, but 200 on `/report`.
5. Node compatibility: sign a token with Node `jsonwebtoken` using the same secret (from `src-node/` before deleting `node_modules`, or a hand-built HS256), and check that PHP accepts it.
6. `GET /report?from&to&limit&skip` only returns the caller's logs.
7. `GET /admin` loads the dashboard, and the filters and paging work against `/admin/logs` and `/admin/services`.
8. `GET /docs` and `/help` work, and `/openapi.yaml` is served.
9. `config.json` can't be reached over HTTP because it's outside the docroot.
