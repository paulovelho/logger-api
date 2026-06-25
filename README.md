# Logger API

Flexible logging API with JWT authentication. Each service authenticates and can only see its own logs.

## Setup

```bash
docker compose up -d
```

The API runs on `http://localhost:3000`.

## Configuration

**`.env`** — JWT secret and MySQL connection settings.

**`config.json`** — Service credentials (userId + secret pairs). Add new services here.

## API

### POST /login

Get a JWT token.

```bash
curl -X POST http://localhost:3000/login \
  -H "Content-Type: application/json" \
  -d '{"userId": "service-website", "secret": "ws-2024-key"}'
```

Response:
```json
{"token": "eyJhbGciOi..."}
```

### POST /log

Send any JSON payload. The `userId` and `timestamp` are added automatically.

```bash
curl -X POST http://localhost:3000/log \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"event": "page_view", "path": "/home", "ip": "1.2.3.4"}'
```

Response:
```json
{"id": "6712...", "timestamp": "2025-01-15T10:30:00.000Z"}
```

### GET /report

Retrieve logs for the authenticated service. Supports filtering and pagination.

```bash
# All logs (last 100)
curl http://localhost:3000/report \
  -H "Authorization: Bearer <token>"

# With date range
curl "http://localhost:3000/report?from=2025-01-01&to=2025-01-31" \
  -H "Authorization: Bearer <token>"

# With pagination
curl "http://localhost:3000/report?limit=50&skip=100" \
  -H "Authorization: Bearer <token>"
```

Response:
```json
{
  "total": 1,
  "count": 1,
  "logs": [
    {
      "_id": "6712...",
      "userId": "service-website",
      "data": {"event": "page_view", "path": "/home", "ip": "1.2.3.4"},
      "timestamp": "2025-01-15T10:30:00.000Z"
    }
  ]
}
```

### POST /error

Ingest an error entry. `environment` and `service` are extracted as top-level fields; everything else goes into `data`.

```bash
curl -X POST http://localhost:3000/error \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"environment": "production", "service": "crawler", "message": "Timeout", "url": "https://example.com"}'
```

### GET /errors

Retrieve error entries for the authenticated service. Supports the same filtering and pagination as `/report`.

```bash
curl http://localhost:3000/errors \
  -H "Authorization: Bearer <token>"
```

### GET /admin/logs, /admin/errors, /admin/services

Cross-service admin queries. Require a valid Bearer token (any service credential works). The `/admin` dashboard at `http://localhost:3000/admin` provides a UI for these endpoints with a login form.

```bash
curl http://localhost:3000/admin/logs?limit=50 \
  -H "Authorization: Bearer <token>"

curl http://localhost:3000/admin/services \
  -H "Authorization: Bearer <token>"
```

### GET /health

```bash
curl http://localhost:3000/health
```

## Adding a new service

Add an entry to `config.json`:

```json
{
  "userId": "service-payments",
  "secret": "pay-2024-key",
  "name": "Payment Service"
}
```

Restart the API: `docker compose restart api`
