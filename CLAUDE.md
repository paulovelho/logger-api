# Logger API

Part of the **guia.lol** project. A flexible logging microservice where different services authenticate via JWT and log arbitrary JSON data, each isolated to their own logs.

## Stack

- Node.js + Express + MySQL (via `mysql2`)
- JWT authentication (jsonwebtoken)
- No build step, no TypeScript, no test framework yet

## Project Structure

```
src/
  index.js            # Entry point — Express app + MySQL connection
  middleware/auth.js   # JWT Bearer token verification, sets req.userId
  routes/auth.js       # POST /login — validates against config.json, returns JWT
  routes/log.js        # POST /log — stores arbitrary JSON with userId from token
  routes/report.js     # GET /report — returns logs filtered by userId, with date range + pagination
  routes/error.js      # POST /error — stores error entry (environment, service, data)
  routes/errors.js     # GET /errors — returns errors filtered by userId, with date range + pagination
  routes/admin.js      # GET /admin/logs + /admin/errors + /admin/services — all data, Bearer auth required
public/admin.html      # Admin dashboard UI, served at GET /admin
config.json            # Service user credentials (userId + secret pairs)
.env                   # JWT_SECRET, MYSQL_*, PORT
docker-compose.yml     # API service connected to guialolnetwork (MySQL is external)
database/schema.sql    # MySQL schema: logger_logs + logger_errors tables
```

## Key Design Decisions

- **Users live in `config.json`**, not the database — services are static, no CRUD needed.
- **`data` field is JSON** — each service logs whatever JSON structure it wants.
- **`userId` comes from the JWT only**, never from the request body — prevents spoofing.
- **`environment` and `service` are top-level columns** — extracted from the request body, default to `unknown`.
- **Errors live in a separate `logger_errors` table** — keeps error queries isolated and fast.
- **Indexes** on `userId`+`timestamp` and `service` for efficient filtering.
- **JWTs never expire** — service-to-service tokens are long-lived by design.
- No rate limiting or body validation on `/log` or `/error` (kept simple).

## Running

```bash
docker compose up -d          # Start API (MySQL is on guialolnetwork, external)
docker compose restart api    # After config changes
npm run dev                   # Local dev with --watch (needs local MySQL)
```

## API Endpoints

| Method | Path            | Auth     | Purpose                                              |
|--------|-----------------|----------|------------------------------------------------------|
| POST   | /login          | No       | Get JWT token (userId + secret)                      |
| POST   | /log            | Bearer   | Ingest any JSON payload as a log entry               |
| GET    | /report         | Bearer   | Query own logs (from, to, limit, skip)               |
| POST   | /error          | Bearer   | Ingest any JSON payload as an error entry            |
| GET    | /errors         | Bearer   | Query own errors (from, to, limit, skip)             |
| GET    | /health         | No       | Health check                                         |
| GET    | /admin          | No       | Admin dashboard UI (login form; data fetched via Bearer) |
| GET    | /admin/logs     | Bearer   | All logs, any service (userId, service, from, to, limit, skip) |
| GET    | /admin/errors   | Bearer   | All errors, any service (userId, service, from, to, limit, skip) |
| GET    | /admin/services | Bearer   | Per-service log counts + last activity               |
| GET    | /docs           | No       | Swagger UI (renders openapi.yaml)                    |
| GET    | /help           | No       | Redirects to /docs                                   |

## Notes

- `blueprint.md` contains the full original specification and build history.
- No tests exist yet.
- No `node_modules` or lockfile in the repo — run `npm install` before local dev.
