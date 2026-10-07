# Logger API

Flexible logging API with JWT authentication. Each service authenticates, writes arbitrary JSON
logs and errors, and can only read back its own. An admin credential reads and purges everything.
PHP (MagratheaPHP2) + MariaDB. Several instances can run side by side, each with its own database and config.

Deploying an instance: see [deploy.md](deploy.md). Release notes: [changelog.md](changelog.md).

## Local setup (Docker)

```bash
cp .env.example .env                                      # JWT_SECRET must be ≥ 32 bytes
cp src/configs/magrathea.conf.example src/configs/magrathea.conf
sed -i 's/use_environment = "production"/use_environment = "dev"/' src/configs/magrathea.conf
cp config.example.json config.json && ./scripts/configure.sh
(cd src && composer install)
./scripts/reboot.sh                                       # builds, starts, waits for /health-check
```

The API runs on `http://localhost:3002` (`PORT` in `.env`). MariaDB runs in `logger_db` and gets
`logger_logs` / `logger_errors` from `database/schema.sql` on first start.

## Configuration

- **`src/configs/magrathea.conf`**: database, `jwt_key` (≥ 32 bytes), log/cache paths. The `[dev]` section reads `.env`.
- **`config.json`**: service credentials (`service` + `secret`, optional `name`, `"readonly": true`
  for the admin credential, `"active": false` to block writes). Read on every request.
- **`cors-origins.json`**: browser origins allowed to call the API (e.g. the admin app).

## API

Every response is `{"success": bool, "data": ...}`. Errors carry the real HTTP status and
`data.message`. Full reference at `/docs` (Swagger UI over `src/app/openapi.yaml`).

| Method | Path | Auth | `data` |
|---|---|---|---|
| POST | `/login` | – | `{token}` |
| POST | `/token` | – | `{decoded}` |
| POST | `/log`, `/error` | Bearer, active service | `{id, timestamp}` |
| POST | `/log/batch`, `/error/batch` | Bearer, active service | `{count}` (1–100 entries, all or nothing) |
| GET | `/report`, `/errors` | Bearer | `{total, count, logs\|errors}` (own entries) |
| GET | `/admin/logs`, `/admin/errors` | Bearer, readonly | `{total, count, logs\|errors}` (all, `service` filter) |
| GET | `/admin/services` | Bearer, readonly | `{services}` |
| DELETE | `/admin/logs`, `/admin/errors` | Bearer, readonly | `{deleted}` (`service`, `olderThanDays` = 365) |
| GET | `/health-check`, `/version` | – | `{health, time, database}`, `{version}` |

```bash
curl -X POST http://localhost:3002/login -H "Content-Type: application/json" \
  -d '{"service": "service-website", "secret": "change-me"}'
# {"success":true,"data":{"token":"eyJhbGciOi..."}}

curl -X POST http://localhost:3002/log -H "Content-Type: application/json" -H "Authorization: Bearer <token>" \
  -d '{"environment": "production", "event": "page_view", "path": "/home"}'
# {"success":true,"data":{"id":1,"timestamp":"2026-10-05T12:00:00.123Z"}}

curl "http://localhost:3002/report?from=2026-01-01&limit=50" -H "Authorization: Bearer <token>"
# {"success":true,"data":{"total":1,"count":1,"logs":[{"_id":1,"service":"service-website","serviceName":"Website",
#   "environment":"production","data":{"event":"page_view","path":"/home"},
#   "timestamp":"2026-10-05T12:00:00.123Z","occurredAt":"2026-10-05T12:00:00.123Z"}]}}

curl -X POST http://localhost:3002/log/batch -H "Content-Type: application/json" -H "Authorization: Bearer <token>" \
  -d '{"environment": "production", "sentAt": "2026-10-07T14:03:11Z", "entries": [
        {"occurredAt": "2026-10-05T09:12:40Z", "event": "game_start"}, {"event": "game_over"}]}'
# {"success":true,"data":{"count":2}}
```

`environment` (default `unknown`) is stored in its own column; the rest of the body becomes `data`.
`occurredAt` / `sentAt` (optional, ISO-8601) set when the event happened: with both, the stored
`occurredAt` is `arrival - (sentAt - occurredAt)`, so a queued event keeps its age even if the
client's clock is wrong. `timestamp` is always the arrival time. `from` / `to` filter on `occurredAt`.

### Tests

```bash
(cd src && composer install)       # PHPUnit is a dev dependency
./scripts/test.sh                  # runs inside logger_php; --filter BatchTest etc. pass through
```

Unit tests for the clock-skew rules, plus integration tests that call the API on the container's
`http://localhost` and check the database. Every row they write is tagged and deleted afterwards.

### Admin dashboard

`/admin` is a dashboard over every service's logs and errors. Sign in with the readonly
credential: the token is kept in the browser's localStorage, and every `/admin/*` call sends it as
a Bearer token.

## Adding a new service

Run `./scripts/configure.sh` (or add an entry to `config.json`). No restart needed.
