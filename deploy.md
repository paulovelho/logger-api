# Deploying a Logger instance

The logger is a plain PHP app (MagratheaPHP2) backed by two MariaDB/MySQL tables, `logger_logs`
and `logger_errors`. It can run as **several independent instances** (one per use): each instance
has its own checkout, its own database, its own `magrathea.conf` and `config.json`, and its own
vhost. Nothing in the code is tied to a host name or a path.

```
service ──HTTPS──> Apache or Caddy (<host>) ──> PHP (src/app/index.php) ──> MariaDB: <database>.logger_logs / logger_errors
```

The last section walks through **guia.lol production** as a worked example, including the switch
away from the Node 1.1.x version. That switch needs no data migration, because both versions use
the same tables.

Placeholders used below:

| Placeholder | Meaning | guia.lol prod |
|---|---|---|
| `<path>` | the checkout | `/var/www/guia.lol/logger` |
| `<host>` | the public host name | `logger.guia.lol` |
| `<database>` | the instance's database | `guia_lol` |

---

## 1. Requirements

- PHP **8.2+** with `mysqli` (8.4 recommended; under Apache via mod_php or PHP-FPM, under Caddy via PHP-FPM)
- Composer
- MariaDB 10.2.7+ or MySQL 5.7+ (needs `JSON`)
- Apache with `mod_rewrite` + `mod_headers`, **or** Caddy 2

## 2. Get the code

```bash
git clone <repo-url> <path>
cd <path>/src
composer install --no-dev
```

Only `<path>/src/app` is served. `config.json`, `cors-origins.json`, `src/configs/` and
`src/vendor/` sit outside the document root and can't be reached over HTTP.

## 3. Create the tables

Run `database/schema.sql` against the instance's database. Create the database and user first if
they're new.

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS <database> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p <database> < database/schema.sql
mysql -u root -p -e "CREATE USER IF NOT EXISTS '<db-user>'@'localhost' IDENTIFIED BY '<db-password>';
  GRANT SELECT, INSERT, DELETE, DROP ON <database>.logger_logs TO '<db-user>'@'localhost';
  GRANT SELECT, INSERT, DELETE, DROP ON <database>.logger_errors TO '<db-user>'@'localhost'"
```

- The tables have to exist before the table-level `GRANT`. If you reuse a database user that
  already has access, like `guia_lol` in prod, skip the last command.
- `schema.sql` uses `CREATE TABLE IF NOT EXISTS`, so running it again is harmless.
- `DELETE` is needed by the purge routes (`DELETE /admin/logs|errors`). `DROP` is only needed by
  `./scripts/erase.sh`, which runs `TRUNCATE`.

## 4. Configure `src/configs/magrathea.conf`

```bash
cp src/configs/magrathea.conf.example src/configs/magrathea.conf
```

Keep `use_environment = "production"` and fill in the `[production]` section:

| Key | Value |
|---|---|
| `db_host`, `db_name`, `db_user`, `db_pass` | the database from step 3 |
| `logs_path`, `cache_path` | `<path>/logs` and `<path>/cache` |
| `jwt_key` | **at least 32 bytes**: `openssl rand -base64 48` |
| `timezone` | keep `UTC`: the DB fills `timestamp`, and the API labels it UTC (`…Z`) |

> ⚠️ `jwt_key` signs every service token. Changing it invalidates all tokens already issued, so
> every service has to log in again. A key under 32 bytes makes `/login` and every authenticated
> route answer 500 `jwt_key (magrathea.conf) must be at least 32 bytes`.

> ⚠️ The database server's clock must be UTC. Check `SELECT @@global.time_zone, @@system_time_zone`.
> Otherwise every timestamp is off by the server's offset.

## 5. Writable folders

Magrathea writes its own error log and cache. The web server user must be able to write to them:

```bash
mkdir -p <path>/logs <path>/cache
sudo chown www-data:www-data <path>/logs <path>/cache
```

> ⚠️ If `logs/` isn't writable, every API error (even a plain 401) turns into an HTML
> "Magrathea Error!" page with status 200 instead of the JSON envelope.

## 6. Configure services (`config.json`)

```bash
cp config.example.json config.json
./scripts/configure.sh          # validates the JSON and adds services interactively
```

`config.json` is read on every request, so changes take effect immediately (no restart).

- Each entry is `{ "service": "...", "secret": "...", "name": "..." }`. Older entries that use
  `"userId"` instead of `"service"` still work.
- `"readonly": true` marks the **admin credential**. It may call `/admin/*` (the dashboard and the
  admin app). It can still write. Every other service gets 403 on `/admin/*`.
- `"active": false` blocks a service from writing (`/log` and `/error` → 403) and hides it from
  `/admin/services`. It can still read its own logs.
- **Removing an entry revokes that service's tokens**: authenticated routes check the service still exists.

## 7. Configure CORS (`cors-origins.json`)

A JSON array of the browser origins allowed to call the API, e.g. the admin app:

```json
["https://admin.guia.lol", "http://localhost:4200"]
```

Requests from a listed origin get `Access-Control-Allow-Origin` back; others don't. Services
calling server-to-server aren't affected. If the file is missing or invalid, no origin is allowed.
Don't add `Access-Control-*` headers in the web server too, or browsers will reject the duplicates.

## 8a. Web server: Apache

The app ships its own `src/app/.htaccess` (routing, static pages, security headers).

1. Enable the modules:
   ```bash
   sudo a2enmod rewrite headers
   ```
2. Add a vhost, e.g. `/etc/apache2/sites-available/<host>.conf`:
   ```apache
   <VirtualHost *:80>
       ServerName <host>
       DocumentRoot <path>/src/app

       <Directory <path>/src/app>
           Options FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
3. Enable it and get a certificate (certbot adds the `:443` vhost and the HTTP→HTTPS redirect):
   ```bash
   sudo a2ensite <host>
   sudo systemctl reload apache2
   sudo certbot --apache -d <host>
   ```

> 💡 If `/health-check` returns Apache's own 404 page, the `.htaccess` isn't being read: check
> `AllowOverride`. A 500 usually means a module is missing: check `/var/log/apache2/error.log`.

## 8b. Web server: Caddy

1. Copy `site.caddy.example` into the server's Caddyfile and replace `<host>` and `<path>`.
   Adjust the `php_fastcgi` socket to the installed PHP-FPM version.
2. Reload:
   ```bash
   sudo systemctl reload caddy
   ```

There is no basic auth on `/admin`. The page is only a shell with a login form, and its API
requires the admin credential's Bearer token.

## 9. Test it

```bash
URL=https://<host>

# 1. Is it up, and can it reach the database?
curl $URL/health-check
# → {"success":true,"data":{"health":"ok","time":"…","database":"ok"}}
curl $URL/version
# → {"success":true,"data":{"version":"2.0.0"}}

# 2. Log in as a service from config.json
TOKEN=$(curl -s -X POST $URL/login \
  -H "Content-Type: application/json" \
  -d '{"service":"<service>","secret":"<its-secret>"}' | sed 's/.*"token":"\([^"]*\)".*/\1/')

# 3. Send a log and an error
curl -X POST $URL/log -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"environment":"production","event":"deploy-test","ok":true}'
# → {"success":true,"data":{"id":123,"timestamp":"…Z"}}
curl -X POST $URL/error -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"environment":"production","event":"deploy-test-error"}'

# 4. Read them back
curl "$URL/report?limit=5" -H "Authorization: Bearer $TOKEN"
curl "$URL/errors?limit=5" -H "Authorization: Bearer $TOKEN"

# 5. Bad credentials fail
curl -i -X POST $URL/login -H "Content-Type: application/json" -d '{"service":"<service>","secret":"wrong"}'
# → HTTP 401 {"success":false,"data":{…,"message":"Invalid credentials"}}

# 6. The admin API needs the readonly credential
curl -i $URL/admin/services -H "Authorization: Bearer $TOKEN"
# → HTTP 403 (unless <service> is the readonly one)
```

Finally, open `https://<host>/admin` in the browser and sign in with the readonly credential. The
`deploy-test` entries should be listed. API docs are at `https://<host>/docs`.

### Maintenance

```bash
./scripts/erase.sh                 # ⚠️ TRUNCATE both tables — reads credentials from magrathea.conf, asks for confirmation
./scripts/erase.sh production      # same, choosing the magrathea.conf section explicitly
mysqldump <database> logger_logs logger_errors > logger-$(date +%F).sql     # backup
```

To drop old entries for one service, use the purge routes with the readonly token, e.g.
`DELETE /admin/logs?service=crawler&olderThanDays=90`. Each purge leaves an audit entry under the
`logger` service.

---

## 10. Add a new service

1. Run `./scripts/configure.sh` (or edit `config.json` by hand, see step 6). No restart needed.
2. Get its token:
   ```bash
   curl -s -X POST https://<host>/login -H "Content-Type: application/json" \
     -d '{"service":"<new-service>","secret":"<the-secret>"}'
   ```
3. Put the URL and token in the calling service's config. Existing clients (guia.lol):

   | Client | Config | Calls |
   |---|---|---|
   | crawler | `LOGGER_URL` / `LOGGER_TOKEN` | `POST /log` |
   | api | `logger_url` / `logger_token` (magrathea.conf) | `POST /log`, `POST /error` |
   | auth | `LOGGER_URL` / `LOGGER_TOKEN` | `POST /log` |
   | profiles | `LOGGER_API_URL` / `LOGGER_TOKEN` | `POST /error` |
   | linktree-importer | `LOGGER_URL` / `LOGGER_TOKEN` | `POST /log` |
   | admin app | readonly credential | `/admin/*` (reads, purge) |
   | status | none | `GET /health-check`, `GET /version` |

   The writers are fire-and-forget and ignore the response body.

Tokens never expire. To cut one off, remove the service from `config.json` (or rotate `jwt_key`,
which cuts off everyone).

---

## 11. Worked example: guia.lol production (replacing Node 1.1.x)

Today `logger.guia.lol` is the Node container (`guia_lol-logger`, `network_mode: host`, port 3002)
behind a reverse proxy. It writes to `logger_logs` / `logger_errors` in `guia_lol`. The PHP version
reads and writes the **same tables**, so nothing is migrated, and the old logs stay visible.

1. **JWT secret.** In `/var/www/guia.lol/logger`, before pulling:
   ```bash
   awk -F= '/^JWT_SECRET=/{print length($2)}' .env
   ```
   - **32 or more**: reuse it as `jwt_key`. Every token the clients already hold keeps working.
   - **Under 32**: generate a new key (`openssl rand -base64 48`). At the switch, log every client
     in again (table in step 10) and update its token.
2. **DB timezone.** `SELECT @@global.time_zone, @@system_time_zone` must be UTC (see step 4).
3. **Pull and install** (the Node container keeps running until step 5):
   ```bash
   git pull
   cd src && composer install --no-dev && cd ..
   ```
4. **Set up the instance**: steps 4–7 above, with `<database>` = `guia_lol` and the same DB user
   Node used. The tables already exist (step 3 is a no-op). `config.json` and `cors-origins.json`
   are already there; check them with `./scripts/configure.sh`. The admin app's credential must
   have `"readonly": true`.
5. **Switch the vhost** from the reverse proxy to PHP: replace the `logger.guia.lol` block in the
   Caddyfile with `site.caddy.example` (step 8b), or point the Apache vhost's DocumentRoot at
   `src/app` (step 8a). Reload, then stop the Node container:
   ```bash
   docker compose ls                               # the old project is "logger"
   docker compose -p logger down --remove-orphans
   ```
   The `docker-compose.yml` now in the repo is for local testing only; production doesn't run it.
6. **Smoke test**: `/health-check` and `/version` (step 9). Then check that a fresh crawl appears
   with crawler's existing token, and that the admin app and `/admin` sign in with the readonly
   credential.

Clients don't change, with one exception: the admin app has to read `.data` from the envelope.
