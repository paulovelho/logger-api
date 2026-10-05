# Logger API

Flexible logging API with JWT authentication. Each service authenticates and can only see its own logs.
PHP (MagratheaPHP2) + MariaDB. Several instances can run side by side, each with its own database and config.

Deploying an instance: see [deploy.md](deploy.md).

## Local setup (Docker)

```bash
cp .env.example .env                                      # JWT_SECRET must be ≥ 32 bytes
cp src/configs/magrathea.conf.example src/configs/magrathea.conf
sed -i 's/use_environment = "production"/use_environment = "dev"/' src/configs/magrathea.conf
cp config.example.json config.json && ./configure.sh
(cd src && composer install)
./reboot.sh                                               # builds, starts, waits for /health
```

The API runs on `http://localhost:3002` (`PORT` in `.env`). MariaDB runs in `logger_db` and gets
the `logs` table from `database/logs.sql` on first start.

## Configuration

- **`src/configs/magrathea.conf`**: database, `jwt_key` (≥ 32 bytes), log/cache paths. The `[dev]` section reads `.env`.
- **`config.json`**: service credentials (`userId` + `secret`, optional `"readonly": true`). Read on every request.

## API

Every response is `{"success": bool, "data": ...}`. Full reference at `/docs` (Swagger UI over `src/app/openapi.yaml`).

### POST /login

```bash
curl -X POST http://localhost:3002/login \
  -H "Content-Type: application/json" \
  -d '{"userId": "service-website", "secret": "ws-2024-key"}'
# {"success":true,"data":{"token":"eyJhbGciOi..."}}
```

### POST /log, POST /error

Send any non-empty JSON object. `userId` and `timestamp` are added automatically.
`/error` also sets `level: "error"` unless the body has its own `level`.

```bash
curl -X POST http://localhost:3002/log \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"event": "page_view", "path": "/home"}'
# {"success":true,"data":{"id":"0199b2f4-…","timestamp":"2026-10-05T12:00:00.123Z"}}
```

### GET /report

The authenticated service's logs, newest first. Query: `from`, `to` (ISO-8601), `limit` (1–1000, default 100), `skip`.

```bash
curl "http://localhost:3002/report?from=2026-01-01&limit=50" -H "Authorization: Bearer <token>"
# {"success":true,"data":{"total":1,"count":1,"logs":[{"id":"…","userId":"service-website","data":{…},"timestamp":"…Z"}]}}
```

### Admin

`/admin` is a dashboard over every service's logs (`/admin/logs`, `/admin/services`). It has no
auth of its own; the web server protects `/admin*` with basic auth (see deploy.md).

### GET /health

```bash
curl http://localhost:3002/health
# {"success":true,"data":{"status":"ok","database":"ok"}}
```

## Adding a new service

Run `./configure.sh` (or add an entry to `config.json`). No restart needed.
