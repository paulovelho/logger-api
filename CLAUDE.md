# Logger API

Part of the **guia.lol** project. A flexible logging microservice where different services authenticate via JWT and log arbitrary JSON data, each isolated to their own logs.

## Stack

- Node.js + Express + Mongoose
- MongoDB (via Docker)
- JWT authentication (jsonwebtoken)
- No build step, no TypeScript, no test framework yet

## Project Structure

```
src/
  index.js            # Entry point — Express app + Mongoose connection
  middleware/auth.js   # JWT Bearer token verification, sets req.userId
  models/Log.js       # Mongoose schema: { userId, data (Mixed), timestamp }
  routes/auth.js       # POST /login — validates against config.json, returns JWT
  routes/log.js        # POST /log — stores arbitrary JSON with userId from token
  routes/report.js     # GET /report — returns logs filtered by userId, with date range + pagination
  routes/admin.js      # GET /admin/logs + /admin/services — all data, no auth (yet)
public/admin.html      # Admin dashboard UI, served at GET /admin
config.json            # Service user credentials (userId + secret pairs)
.env                   # JWT_SECRET, MONGO_URI, PORT
docker-compose.yml     # Two services: api + mongo:7
```

## Key Design Decisions

- **Users live in `config.json`**, not the database — services are static, no CRUD needed.
- **`data` field is `Mixed`** — each service logs whatever JSON structure it wants.
- **`userId` comes from the JWT only**, never from the request body — prevents spoofing.
- **Indexes** on `userId` and `timestamp` (the two filter fields in `/report`).
- **JWTs never expire** — service-to-service tokens are long-lived by design.
- No rate limiting or body validation on `/log` (kept simple).

## Running

```bash
docker compose up -d          # Start API + MongoDB
docker compose restart api    # After config changes
npm run dev                   # Local dev with --watch (needs local MongoDB)
```

## API Endpoints

| Method | Path      | Auth     | Purpose                          |
|--------|-----------|----------|----------------------------------|
| POST   | /login    | No       | Get JWT token (userId + secret)  |
| POST   | /log      | Bearer   | Ingest any JSON payload          |
| GET    | /report   | Bearer   | Query logs (from, to, limit, skip) |
| GET    | /health   | No       | Health check                     |
| GET    | /admin    | No       | Admin dashboard (browse all logs) |
| GET    | /admin/logs | No     | All logs, any service (userId, from, to, limit, skip) |
| GET    | /admin/services | No | Per-service log counts + last activity |
| GET    | /docs     | No       | Swagger UI (renders openapi.yaml)  |
| GET    | /help     | No       | Redirects to /docs               |

## Notes

- `blueprint.md` contains the full original specification and build history.
- No tests exist yet.
- No `node_modules` or lockfile in the repo — run `npm install` before local dev.
