# Logger API

Flexible logging API with JWT authentication. Each service authenticates and can only see its own logs.

## Setup

```bash
docker compose up -d
```

The API runs on `http://localhost:3000`.

## Configuration

**`.env`** — JWT secret and MongoDB URI.

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
