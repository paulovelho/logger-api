# Logger API — Project Blueprint

This document describes the full specification and every task performed to build this project from scratch.

---

## Original Requirements

Build a logging API with the following specs:

- **Stack:** Node.js + Express + MongoDB
- **Two main endpoints:** `/log` and `/report`
- **Authentication:** JWT tokens, with users stored in a `config.json` file (no database needed for auth)
- **`/log` endpoint:** Accepts any JSON payload, automatically adds `userId` (from JWT) and `timestamp`
- **`/report` endpoint:** Returns logs filtered by the authenticated user's `userId`
- **`/login` endpoint:** Issues JWT tokens after validating credentials against `config.json`
- **Dockerized:** `docker-compose` with API + MongoDB containers
- **Deliverables:** All source files, Dockerfile, docker-compose.yml, config.json, .env, README.md, example API requests

**Core concept:** Different services can log flexible/arbitrary data, and each service can only see their own logs based on their `userId`.

---

## Tasks Performed

### 1. Initialize project structure

Created the empty directory structure:

```
logger/
└── src/
    ├── middleware/
    ├── models/
    └── routes/
```

### 2. Create `package.json`

Defined the project with dependencies:
- `express` — HTTP framework
- `jsonwebtoken` — JWT sign/verify
- `mongoose` — MongoDB ODM
- `dotenv` — Environment variable loading

Scripts: `start` (production) and `dev` (with `--watch` for auto-reload).

### 3. Create `config.json`

Added three sample service users, each with:
- `userId` — unique identifier (e.g. `service-website`)
- `secret` — password used to obtain a JWT
- `name` — human-readable label

### 4. Create `.env`

Environment variables:
- `JWT_SECRET` — secret key for signing JWTs
- `MONGO_URI` — MongoDB connection string (pointing to the `mongo` Docker service)
- `PORT` — API port (default 3000)

### 5. Create `src/models/Log.js`

Mongoose schema for log entries:
- `userId` (String, indexed) — set automatically from JWT, not from request body
- `data` (Mixed) — the arbitrary JSON payload sent by the client
- `timestamp` (Date, indexed) — defaults to `Date.now`
- Disabled `versionKey` (`__v`) since logs are write-once

### 6. Create `src/middleware/auth.js`

JWT authentication middleware:
- Extracts `Bearer <token>` from the `Authorization` header
- Verifies the token using `JWT_SECRET`
- Sets `req.userId` from the token payload
- Returns 401 on missing/invalid tokens

### 7. Create `src/routes/auth.js` (POST /login)

Login route:
- Accepts `{ userId, secret }` in the request body
- Looks up the user in `config.json` by matching both fields
- Signs a JWT with `{ userId }` payload, 30-day expiry
- Returns `{ token }`

### 8. Create `src/routes/log.js` (POST /log)

Log ingestion route (protected by auth middleware):
- Takes the entire request body as `data`
- Creates a MongoDB document with `userId` from JWT and `data` from body
- Returns `{ id, timestamp }` with status 201

### 9. Create `src/routes/report.js` (GET /report)

Log retrieval route (protected by auth middleware):
- Filters logs by `userId` from JWT (users can only see their own logs)
- Optional query params: `from`, `to` (date range), `limit` (default 100), `skip` (pagination)
- Returns `{ total, count, logs }` sorted newest-first

### 10. Create `src/index.js`

Application entry point:
- Loads environment variables with `dotenv`
- Sets up Express with JSON body parsing
- Mounts routes: `/login`, `/log`, `/report`, `/health`
- Connects to MongoDB, then starts the HTTP server
- Exits on connection failure

### 11. Create `Dockerfile`

Multi-stage Node.js container:
- Base image: `node:20-alpine`
- Copies `package.json` first for layer caching
- Installs production dependencies only (`--omit=dev`)
- Copies source files
- Exposes port 3000

### 12. Create `docker-compose.yml`

Two services:
- **api** — builds from Dockerfile, maps port from `.env`, depends on mongo
- **mongo** — official `mongo:7` image with a named volume for data persistence

### 13. Create `.dockerignore`

Excludes `node_modules` and `.git` from the Docker build context.

### 14. Create `README.md`

Documentation covering:
- Setup instructions (`docker compose up -d`)
- Configuration files explanation
- Full API reference with curl examples for every endpoint
- Response format examples
- Instructions for adding new services

---

## Architecture Decisions

| Decision | Rationale |
|---|---|
| Users in `config.json`, not DB | Simpler setup; services are relatively static; no need for user CRUD |
| `data` field as `Mixed` type | Allows any JSON structure — each service logs whatever it needs |
| `userId` from JWT only, never from body | Prevents spoofing; a service can never write logs as another service |
| Indexes on `userId` and `timestamp` | These are the two fields used for filtering in `/report` |
| 30-day token expiry | Long enough for service-to-service use; short enough to rotate |
| No rate limiting or validation on `/log` body | Kept simple; can be added later if needed |

---

## File Manifest

| File | Purpose |
|---|---|
| `package.json` | Project metadata and dependencies |
| `config.json` | Service user credentials |
| `.env` | Environment variables |
| `src/index.js` | App entry point, Express + Mongoose setup |
| `src/middleware/auth.js` | JWT verification middleware |
| `src/models/Log.js` | Mongoose schema for log entries |
| `src/routes/auth.js` | POST /login — issue JWT tokens |
| `src/routes/log.js` | POST /log — ingest log entries |
| `src/routes/report.js` | GET /report — retrieve filtered logs |
| `Dockerfile` | Container image definition |
| `docker-compose.yml` | Multi-container orchestration |
| `.dockerignore` | Build context exclusions |
| `README.md` | Setup and usage documentation |
