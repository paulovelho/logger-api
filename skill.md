# Logger API

Flexible logging microservice for guia.lol. Each service authenticates with its own JWT and can only see its own logs.

## Base URLs

- **Production**: `https://logger.guia.lol`
- **Local dev**: `http://localhost:3106`

## Auth Flow

Authentication is a two-step process: get a token once, then use it on every request.

### Step 1 — Login (POST /login)

Credentials live in the server's `config.json` under a `users` array. Each entry has a `service` and `secret`.

```bash
curl -X POST https://logger.guia.lol/login \
  -H "Content-Type: application/json" \
  -d '{"service": "crawler", "secret": "crawler-secret"}'
```

Response:
```json
{"token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."}
```

**Tokens never expire.** Store and reuse — don't login on every request.

### Step 2 — Use the Bearer token

All protected endpoints require:
```
Authorization: Bearer <token>
```

The `service` identity is extracted from the JWT server-side — never pass it in the body.

---

## Endpoints

### POST /log — Ingest a log entry

Send any JSON payload. `service` and `timestamp` are added automatically. Optionally include `environment` as a top-level field.

```bash
curl -X POST https://logger.guia.lol/log \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"environment": "production", "event": "crawl_complete", "url": "https://example.com"}'
```

Response (201):
```json
{"id": 42, "timestamp": "2025-01-15T10:30:00.000Z"}
```

### POST /error — Ingest an error entry

Same shape as `/log` but stored in a separate `logger_errors` table.

```bash
curl -X POST https://logger.guia.lol/error \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"environment": "production", "message": "Timeout on crawl", "url": "https://example.com"}'
```

Response (201):
```json
{"id": 7, "timestamp": "2025-01-15T10:31:00.000Z"}
```

---

### GET /report — Query own logs

Returns logs for the authenticated service only.

```bash
curl "https://logger.guia.lol/report?from=2025-01-01&to=2025-01-31&limit=50&skip=0" \
  -H "Authorization: Bearer <token>"
```

Query params:
| Param   | Type | Default | Description                      |
|---------|------|---------|----------------------------------|
| `from`  | date | —       | Start date (inclusive), ISO 8601 |
| `to`    | date | —       | End date (inclusive), ISO 8601   |
| `limit` | int  | 100     | Max entries to return            |
| `skip`  | int  | 0       | Entries to skip (pagination)     |

Response (200):
```json
{
  "total": 42,
  "count": 10,
  "logs": [
    {
      "_id": 38,
      "service": "crawler",
      "environment": "production",
      "data": {"event": "crawl_complete", "url": "https://example.com"},
      "timestamp": "2025-01-15T10:30:00.000Z"
    }
  ]
}
```

### GET /errors — Query own errors

Same params and response shape as `/report`, but returns from `logger_errors`. Response key is `errors` instead of `logs`.

---

### GET /admin/logs — All logs (admin)

Like `/report` but returns logs for **all services**. Adds optional `service` filter.

```bash
curl "https://logger.guia.lol/admin/logs?service=crawler&limit=50" \
  -H "Authorization: Bearer <admin-token>"
```

Extra query param:
| Param     | Type   | Description              |
|-----------|--------|--------------------------|
| `service` | string | Filter by service name   |

### GET /admin/errors — All errors (admin)

Same as `/admin/logs` but from `logger_errors`.

### GET /admin/services — Per-service summary (admin)

Returns log counts and last activity per service.

```bash
curl https://logger.guia.lol/admin/services \
  -H "Authorization: Bearer <admin-token>"
```

Response:
```json
{
  "services": [
    {"service": "crawler", "count": 1204, "lastLog": "2025-01-15T10:30:00.000Z"}
  ]
}
```

---

### GET /health — Health check

```bash
curl https://logger.guia.lol/health
# {"status": "ok"}
```

---

## Integration Examples

### Node.js / fetch

```js
const BASE_URL = 'https://logger.guia.lol';

async function getToken(service, secret) {
  const res = await fetch(`${BASE_URL}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ service, secret }),
  });
  const { token } = await res.json();
  return token; // store this — it never expires
}

async function log(token, data) {
  const res = await fetch(`${BASE_URL}/log`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(data), // include environment: 'production' if needed
  });
  return res.json(); // { id, timestamp }
}

async function logError(token, data) {
  const res = await fetch(`${BASE_URL}/error`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(data),
  });
  return res.json(); // { id, timestamp }
}
```

### PHP / curl

```php
function logger_login(string $service, string $secret): string {
    $ch = curl_init('https://logger.guia.lol/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['service' => $service, 'secret' => $secret]),
    ]);
    $body = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $body['token'];
}

function logger_log(string $token, array $data): array {
    $ch = curl_init('https://logger.guia.lol/log');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);
    $body = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $body;
}
```

---

## Adding a New Service

Edit `config.json` on the logger server and restart:

```json
{
  "users": [
    {"service": "crawler", "secret": "existing-secret"},
    {"service": "new-service", "secret": "new-secret"}
  ]
}
```

```bash
docker compose restart api
```

---

## Error Responses

All errors follow: `{"error": "message"}`

| Status | Meaning                          |
|--------|----------------------------------|
| 401    | Missing or invalid JWT           |
| 500    | Server or database error         |
