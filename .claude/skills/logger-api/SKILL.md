---
name: logger-api
description: How to authenticate and interact with the guia.lol Logger API. Use when logging events or errors, querying logs, purging old entries, or integrating any service with the logger microservice.
---

# Logger API (1.2.2+)

Logging microservice for guia.lol (PHP + MagratheaPHP2, MariaDB). Each service authenticates with
its own JWT, writes arbitrary JSON, and can only read back its own entries. An admin credential
reads (and purges) everything.

## Base URLs

- **Production**: `https://logger.guia.lol`
- **Local Docker**: `http://localhost:3002` (`PORT` in `.env`)
- **Docs**: `/docs` (Swagger UI of `/openapi.yaml`); admin dashboard at `/admin`

## Response envelope

Every JSON response is the Magrathea envelope. **Read `.data`.**

```json
{"success": true,  "data": { ... }}
{"success": false, "data": {"message": "Invalid token", "code": 401, ...}}
```

Successes are always HTTP 200 (Node 1.x sent 201 on `/log` and `/error`). Errors carry the real
status (400 / 401 / 403 / 404 / 413 / 500).

## Auth

### 1. Login: `POST /login`

Credentials live in the server's `config.json` (`service` + `secret`). `userId` is accepted in
place of `service`.

```bash
curl -X POST https://logger.guia.lol/login \
  -H "Content-Type: application/json" \
  -d '{"service": "service-website", "secret": "..."}'
# {"success":true,"data":{"token":"eyJhbGciOiJIUzI1NiIs..."}}
```

Tokens are HS256 `{service, readonly, iat}` and **never expire**. Log in once, store the token
(e.g. `LOGGER_TOKEN`), and reuse it. Removing the service from `config.json` revokes it.

### 2. Bearer token on every protected route

```
Authorization: Bearer <token>
```

`service` always comes from the token, never from the body.

### `config.json` flags

Read on every request; edits apply immediately with no restart.

| Field | Meaning |
|---|---|
| `service` | id (legacy entries may use `userId`) |
| `secret` | login secret |
| `name` | display name (`serviceName` in results); defaults to the id |
| `readonly` | `true` marks the **admin credential**: may call `/admin/*`. It can still write. |
| `active` | `false` blocks every write (`POST /log`, `/error` and their `/batch` routes: 403) and hides it from `/admin/services` |

## Writing

### `POST /log` and `POST /error`

Send any JSON object. `environment` (string ≤ 50 chars, or a number; default `unknown`) is stored in its own column;
`occurredAt` / `sentAt` (see [Event time](#event-time-occurredat-sentat)) set the event time; the rest becomes `data`. `/log` writes to `logger_logs`, `/error` to `logger_errors`. An empty
body is stored as `{}`. A JSON array or scalar gets a 400. Requires an *active* service.

```bash
curl -X POST https://logger.guia.lol/error \
  -H "Content-Type: application/json" -H "Authorization: Bearer $LOGGER_TOKEN" \
  -d '{"environment": "production", "message": "Failed to fetch page", "url": "https://..."}'
# {"success":true,"data":{"id":42,"timestamp":"2026-10-05T22:59:48.123Z"}}
```

Writers are usually fire-and-forget: don't let a logger failure break the caller.

### Event time: `occurredAt`, `sentAt`

Every entry has a `timestamp` (when the server received it) and an `occurredAt` (when it happened).
Both keys are reserved (never stored in `data`), optional, and must be ISO-8601 strings
(`2026-10-05T09:12:40Z`, `…-03:00`, `2026-10-05`; UTC without an offset). Anything else, including
`null`, a number or `"now"`, is a 400.

For events sent later (offline queues), send both, read from the same device clock. The server only
trusts their difference (the event's age), so a wrong phone clock doesn't matter:

| Sent | Stored `occurredAt` |
|---|---|
| neither | arrival time (as before 1.2.2) |
| `occurredAt` only | as given |
| `occurredAt` + `sentAt` | `arrival - (sentAt - occurredAt)` |
| `sentAt` only | arrival time |

A negative age counts as 0, and `occurredAt` is never later than the arrival time.

### `POST /log/batch` and `POST /error/batch`

Up to 100 entries per request, for clients that queue events. Same auth as `/log` (active service).

```bash
curl -X POST https://logger.guia.lol/log/batch \
  -H "Content-Type: application/json" -H "Authorization: Bearer $LOGGER_TOKEN" \
  -d '{
    "environment": "production",
    "sentAt": "2026-10-07T14:03:11Z",
    "entries": [
      {"occurredAt": "2026-10-05T09:12:40Z", "event": "game_start", "mode": "classic"},
      {"occurredAt": "2026-10-05T09:31:02Z", "event": "game_over", "score": 18432}
    ]
  }'
# {"success":true,"data":{"count":2}}
```

- `sentAt` (**required**) and `environment` are batch-level. Each entry is a JSON object with an
  optional `occurredAt` (missing → arrival time); the rest is its `data`. An entry containing
  `environment` or `sentAt` is a 400.
- `entries`: 1–100 items. Body ≤ 256 KB, otherwise **413**.
- **All or nothing.** Any invalid entry → 400 naming the first one (`entries[3]: must be an object`),
  and nothing is stored. A database failure → 500, nothing stored.
- **Retry rule:** on a 5xx or a network failure, resend the same batch; on a 4xx, drop it (it will
  never be accepted). There is no deduplication: if a response is lost, a resent batch is stored twice.
- All entries share one `timestamp`. The response has no ids.

## Reading

### `GET /report` (own logs) and `GET /errors` (own errors)

| Param | Default | Notes |
|---|---|---|
| `from`, `to` | — | on **`occurredAt`**; any date/time PHP parses; UTC unless an offset is given; inclusive (a bare `to=2026-10-01` means its midnight) |
| `limit` | 100 | clamped to 1–1000 |
| `skip` | 0 | pagination offset |

```json
{"success": true, "data": {
  "total": 42, "count": 1,
  "logs": [{
    "_id": 42, "service": "service-website", "serviceName": "Website",
    "environment": "production", "data": {"event": "page_view"},
    "timestamp": "2026-10-05T22:59:48.123Z",
    "occurredAt": "2026-10-05T22:59:48.123Z"
  }]
}}
```

`/errors` returns the same shape with an `errors` array. Newest first by `occurredAt`.
`timestamp` is the arrival time; `occurredAt` equals it unless the client sent one (UTC, ms).

## Admin (readonly credential only, otherwise 403)

| Method | Path | `data` |
|---|---|---|
| GET | `/admin/logs` | `{total, count, logs}`. Same params as `/report`, plus `service` filter |
| GET | `/admin/errors` | `{total, count, errors}`. Same as above |
| GET | `/admin/services` | `{services: [{service, name, count, lastLog}]}` |
| DELETE | `/admin/logs?service=x&olderThanDays=30` | `{deleted}` |
| DELETE | `/admin/errors?service=x&olderThanDays=30` | `{deleted}` |

- `/admin/services` lists every active configured service, including those with 0 logs, plus the
  synthetic `{service: "logger", name: "Logger (self)"}`. Counts come from `logger_logs` only.
  `lastLog` is the latest arrival `timestamp`.
  Sorted by `lastLog`, newest first, with `null` last.
- **Purge**: `service` is required. `olderThanDays` must be a positive whole number (default 365),
  counted on the arrival `timestamp`.
  Each purge writes an audit row into `logger_logs` under `service: "logger"`:
  `{action: "purge_logs"|"purge_errors", targetService, cutoffDays, deletedCount, performedBy}`.

## System (no auth)

| Path | `data` |
|---|---|
| `GET /health-check` | `{health: "ok", time: "2026-10-05 22:59:48", database: "ok"\|"fail"}` |
| `GET /version` | `{version: "1.2.2"}` |
| `POST /token` `{token}` | `{decoded}` (400 with the JWT error message if invalid) |

## Examples

### Node.js (fetch)

```js
const LOGGER_URL = process.env.LOGGER_URL; // https://logger.guia.lol

async function call(path, { token, method = 'GET', body } = {}) {
  const res = await fetch(LOGGER_URL + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const json = await res.json();
  if (!json.success) throw new Error(json.data?.message || `HTTP ${res.status}`);
  return json.data;
}

const { token } = await call('/login', { method: 'POST', body: { service: 'service-website', secret } });
await call('/log', { token, method: 'POST', body: { environment: 'production', event: 'signup' } });
const { total, logs } = await call('/report?limit=50', { token });

// offline queue: flush up to 100 at a time; keep the batch on 5xx/network errors, drop it on 4xx
async function flush(queue, token) {
  const batch = queue.slice(0, 100);
  let status;
  try {
    const res = await fetch(LOGGER_URL + '/log/batch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ environment: 'production', sentAt: new Date().toISOString(), entries: batch }),
    });
    status = res.status;
  } catch { return; }                                    // network failure: retry later
  if (status < 500) queue.splice(0, batch.length);       // 200 stored, 4xx never will be
}
```

### PHP (curl)

```php
function logger_call(string $method, string $path, ?string $token = null, ?array $body = null): array {
    $ch = curl_init(getenv('LOGGER_URL') . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((object)$body));
    $json = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (!($json['success'] ?? false)) {
        throw new RuntimeException($json['data']['message'] ?? 'logger request failed');
    }
    return $json['data'];
}

$token = logger_call('POST', '/login', null, ['service' => 'service-api', 'secret' => $secret])['token'];
logger_call('POST', '/error', $token, ['environment' => 'production', 'message' => $ex->getMessage()]);
```

## Adding a service

On the logger server, run `./scripts/configure.sh`, or add an entry to `config.json` by hand:

```json
{ "service": "service-payments", "secret": "<random>", "name": "Payment Service" }
```

No restart is needed. Then `POST /login` with it, and store the token in the client's config.
