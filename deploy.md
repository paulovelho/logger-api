# Deploying a Logger instance

The logger is a plain PHP app (MagratheaPHP2) backed by one MariaDB/MySQL table. It can run as
**several independent instances** (one per use): each instance has its own checkout, its own
database, its own `magrathea.conf` and `config.json`, and its own vhost. Nothing in the code is tied
to a host name or a path.

```
service ──HTTPS──> Apache or Caddy (<host>) ──> PHP (src/app/index.php) ──> MariaDB: <database>.logs
```

The last section walks through **guia.lol production** as a worked example, including the switch
away from the old Node + MongoDB version.

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
- MariaDB 10.5+ or MySQL 5.7+ (needs `JSON` and `DATETIME(3)`)
- Apache with `mod_rewrite` + `mod_headers`, **or** Caddy 2

## 2. Get the code

```bash
git clone <repo-url> <path>
cd <path>/src
composer install --no-dev
```

Only `<path>/src/app` is served. `config.json`, `src/configs/` and `src/vendor/` sit outside the
document root and can't be reached over HTTP.

## 3. Create the database table

Run `database/logs.sql` against the instance's database (create the database/user first if it's new):

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS <database> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p <database> < database/logs.sql
mysql -u root -p -e "CREATE USER IF NOT EXISTS '<db-user>'@'localhost' IDENTIFIED BY '<db-password>'; GRANT SELECT, INSERT, DELETE, DROP ON <database>.logs TO '<db-user>'@'localhost'"
```

(The table has to exist before the table-level `GRANT`. Reusing a database user that already has access, like `guia_lol` in prod? Skip the last line.)

The table is always called `logs`. Instances don't share a database, so the name never clashes.
(`DELETE`/`DROP` on the table are only needed by `./erase.sh`, which runs `TRUNCATE`.)

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
| `timezone` | keep `UTC`: timestamps are stored and returned in UTC |

> ⚠️ `jwt_key` signs every service token. Changing it invalidates all tokens already issued, so
> every service has to log in again. A key under 32 bytes makes `/login` and every authenticated
> route answer 500 `jwt_key (magrathea.conf) must be at least 32 bytes`.

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
./configure.sh          # validates the JSON and adds services interactively
```

`config.json` is read on every request, so changes take effect immediately (no restart).

- Each entry is `{ "userId": "...", "secret": "...", "name": "..." }`. Older entries that use
  `"service"` instead of `"userId"` still work.
- `"readonly": true` lets a service read its own `/report` but not write (`/log` and `/error` → 403).
- **Removing an entry revokes that service's tokens**: authenticated routes check the service still exists.

## 7a. Web server: Apache

The app ships its own `src/app/.htaccess` (routing, static pages, admin basic auth, security headers).

1. Enable the modules:
   ```bash
   sudo a2enmod rewrite headers
   ```
2. Create the admin password file:
   ```bash
   sudo htpasswd -c /etc/apache2/.htpasswd-logger admin
   ```
   The `.htaccess` only enables basic auth when this file exists. If you run several instances
   on one server and want separate admin passwords, change the path in both places in
   `src/app/.htaccess`.
3. Add a vhost, e.g. `/etc/apache2/sites-available/<host>.conf`:
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
4. Enable it and get a certificate (certbot adds the `:443` vhost and the HTTP→HTTPS redirect):
   ```bash
   sudo a2ensite <host>
   sudo systemctl reload apache2
   sudo certbot --apache -d <host>
   ```

> 💡 If `/health` returns Apache's own 404 page, the `.htaccess` isn't being read: check `AllowOverride`.
> A 500 usually means a module is missing: check `/var/log/apache2/error.log`.

## 7b. Web server: Caddy

1. Copy `site.caddy.example` into the server's Caddyfile and replace `<host>` and `<path>`.
   Adjust the `php_fastcgi` socket to the installed PHP-FPM version.
2. Hash an admin password and paste it in place of `<admin-hash>`:
   ```bash
   caddy hash-password
   ```
   (On Caddy older than 2.8, the directive is spelled `basicauth`.)
3. Reload:
   ```bash
   sudo systemctl reload caddy
   ```

## 8. Test it

```bash
URL=https://<host>

# 1. Is it up, and can it reach the database?
curl $URL/health
# → {"success":true,"data":{"status":"ok","database":"ok"}}

# 2. Log in as a service from config.json
TOKEN=$(curl -s -X POST $URL/login \
  -H "Content-Type: application/json" \
  -d '{"userId":"<service>","secret":"<its-secret>"}' | sed 's/.*"token":"\([^"]*\)".*/\1/')

# 3. Send a log and an error
curl -X POST $URL/log -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"event":"deploy-test","ok":true}'
# → {"success":true,"data":{"id":"<uuid>","timestamp":"…Z"}}
curl -X POST $URL/error -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"event":"deploy-test-error"}'
# → stored with data.level = "error"

# 4. Read them back
curl "$URL/report?limit=5" -H "Authorization: Bearer $TOKEN"

# 5. Bad credentials fail
curl -i -X POST $URL/login -H "Content-Type: application/json" -d '{"userId":"<service>","secret":"wrong"}'
# → HTTP 401 {"success":false,"data":{…,"message":"Invalid credentials"}}

# 6. The admin is protected
curl -i $URL/admin/services
# → HTTP 401 (basic auth)
```

Finally, open `https://<host>/admin` in the browser. It asks for the basic-auth password and then
shows the `deploy-test` entries. API docs are at `https://<host>/docs`.

### Maintenance

```bash
./erase.sh                 # ⚠️ TRUNCATE logs — reads credentials from magrathea.conf, asks for confirmation
./erase.sh production      # same, choosing the magrathea.conf section explicitly
mysqldump <database> logs > logs-$(date +%F).sql     # backup
```

### About CORS

`LoggerApi` calls `AllowAll()`. Services call the logger server-to-server, where CORS doesn't apply,
and the admin dashboard is same-origin. **Don't call the logger from a frontend**: the browser would
need a service secret or a never-expiring token. Log from a backend instead. Don't add
`Access-Control-*` headers in the web server too, or browsers will reject the duplicates.

---

## 9. Add a new service

1. Run `./configure.sh` (or edit `config.json` by hand, see step 6). No restart needed.
2. Get its token:
   ```bash
   curl -s -X POST https://<host>/login -H "Content-Type: application/json" \
     -d '{"userId":"<new-service>","secret":"<the-secret>"}'
   ```
3. Put the URL and token in the calling service's config. Existing clients:
   - crawler: `LOGGER_URL` / `LOGGER_TOKEN` env vars (`POST /log`)
   - api: `logger_url` / `logger_token` in its `magrathea.conf` (`POST /log` and `POST /error`)

Tokens never expire. To cut one off, remove the service from `config.json` (or rotate `jwt_key`,
which cuts off everyone).

---

## 10. Worked example: guia.lol production (replacing the Node logger)

Today `logger.guia.lol` is a Node container (`guia_lol-logger`) plus MongoDB, behind a reverse proxy
to `127.0.0.1:3002`. Old Mongo logs are **not** migrated; the new table starts empty.

1. **Check the old JWT secret** (in `/var/www/guia.lol/logger`, before pulling):
   ```bash
   awk -F= '/^JWT_SECRET=/{print length($2)}' .env
   ```
   - **32 or more**: reuse it as `jwt_key`. The tokens crawler and api already hold keep working.
   - **Under 32**: generate a new key (`openssl rand -base64 48`). After the switch, log crawler and
     api in again and update crawler's `LOGGER_TOKEN` and api's `logger_token`.
2. **Pull and install** (the old containers keep running until step 5):
   ```bash
   git pull
   cd src && composer install --no-dev && cd ..
   ```
3. **Set up the instance**: steps 3–6 above, with `<database>` = `guia_lol` (the `logs` table goes
   into the shared guia.lol database), `<path>` = `/var/www/guia.lol/logger`, and `jwt_key` from
   step 1. `config.json` is already there; check it with `./configure.sh`.
4. **Switch the vhost** from the reverse proxy to PHP: replace the `logger.guia.lol` block in the
   Caddyfile with `site.caddy.example` (step 7b), or point the Apache vhost's DocumentRoot at
   `src/app` (step 7a). Reload, then run the tests in step 8.
5. **Remove the Node + Mongo stack** and its data:
   ```bash
   docker compose ls                               # the old project is "logger"
   docker compose down --remove-orphans            # stops guia_lol-logger and mongo
   docker volume rm logger_mongo_data              # discards the old logs
   ```
   The `docker-compose.yml` now in the repo is for local testing only; production doesn't run it.

As a side effect, api's `LoggerService::Error()` calls to `POST /error`, which got a 404 from the
Node version, now land.
