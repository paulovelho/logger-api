---
name: logger-api
description: How to authenticate and interact with the guia.lol Logger API. Use when logging events, querying logs, or integrating any service with the logger microservice.
---

# Logger API

Flexible logging microservice for guia.lol. Each service authenticates with its own JWT and can only see its own logs.

## Base URLs

- **Production**: `https://log.guia.lol`
- **Local dev**: `http://localhost:3106`

## Auth Flow

Authentication is a two-step process: get a token once, then use it on every request.

### Step 1 — Login (POST /login)

Credentials live in the server's `config.json`. Each service has a `userId` and `secret`.

```bash
curl -X POST https://log.guia.lol/login \
  -H "Content-Type: application/json" \
  -d '{"userId": "service-website", "secret": "ws-2024-key"}'
```

Response:
```json
{"token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."}
```

Tokens expire in **30 days**. Store and reuse them — don't login on every request.

### Step 2 — Use the Bearer token

All protected endpoints require:
```
Authorization: Bearer <token>
```

The `userId` is extracted from the JWT server-side — never pass it in the body.

---

## Endpoints

### POST /log — Ingest a log entry

Send any JSON payload. `userId` and `timestamp` are added automatically.

```bash
curl -X POST https://log.guia.lol/log \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"event": "page_view", "path": "/home", "ip": "1.2.3.4"}'
```

Response (201):
```json
{"id": "6712abc123def456", "timestamp": "2025-01-15T10:30:00.000Z"}
```

The `data` field accepts any JSON structure — there is no schema enforcement.

---

### GET /report — Query logs

Returns logs for the authenticated service only. Supports date range + pagination.

```bash
# Last 100 logs (default)
curl https://log.guia.lol/report \
  -H "Authorization: Bearer <token>"

# Date range filter
curl "https://log.guia.lol/report?from=2025-01-01&to=2025-01-31" \
  -H "Authorization: Bearer <token>"

# Pagination
curl "https://log.guia.lol/report?limit=50&skip=100" \
  -H "Authorization: Bearer <token>"
```

Query params:
| Param  | Type   | Default | Description                     |
|--------|--------|---------|---------------------------------|
| `from` | date   | —       | Start date (inclusive), ISO 8601 |
| `to`   | date   | —       | End date (inclusive), ISO 8601   |
| `limit`| int    | 100     | Max entries to return            |
| `skip` | int    | 0       | Entries to skip (for pagination) |

Response (200):
```json
{
  "total": 42,
  "count": 10,
  "logs": [
    {
      "_id": "6712abc123def456",
      "userId": "service-website",
      "data": {"event": "page_view", "path": "/home", "ip": "1.2.3.4"},
      "timestamp": "2025-01-15T10:30:00.000Z"
    }
  ]
}
```

- `total` — total matching records (for pagination math)
- `count` — records returned in this response

---

### GET /health — Health check

```bash
curl https://log.guia.lol/health
```

Response: `{"status": "ok"}`

---

## Integration Examples

### Node.js / fetch

```js
const BASE_URL = 'https://log.guia.lol';

async function getToken(userId, secret) {
  const res = await fetch(`${BASE_URL}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ userId, secret }),
  });
  const { token } = await res.json();
  return token;
}

async function log(token, data) {
  const res = await fetch(`${BASE_URL}/log`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(data),
  });
  return res.json(); // { id, timestamp }
}

async function report(token, params = {}) {
  const qs = new URLSearchParams(params).toString();
  const res = await fetch(`${BASE_URL}/report${qs ? '?' + qs : ''}`, {
    headers: { 'Authorization': `Bearer ${token}` },
  });
  return res.json(); // { total, count, logs }
}
```

### PHP / curl

```php
function logger_login(string $userId, string $secret): string {
    $ch = curl_init('https://log.guia.lol/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['userId' => $userId, 'secret' => $secret]),
    ]);
    $body = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $body['token'];
}

function logger_log(string $token, array $data): array {
    $ch = curl_init('https://log.guia.lol/log');
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
  "userId": "service-payments",
  "secret": "pay-2024-key",
  "name": "Payment Service"
}
```

```bash
docker compose restart api
```

---

## Error Responses

All errors follow: `{"error": "message"}`

| Status | Meaning                        |
|--------|--------------------------------|
| 401    | Missing, expired, or invalid JWT |
| 500    | Server or database error        |
