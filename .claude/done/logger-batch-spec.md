# Logger API 1.3.0: batch ingestion and client-side timestamps

Spec for a change to the guia.lol Logger (PHP + MagratheaPHP2, MariaDB). Target
version **1.3.0**. Every existing request must keep working as it does today.

## Why

Mobile clients (first one: a LÖVE2D game) queue events while offline and send
them later. That breaks two assumptions in 1.2:

1. **`timestamp` is the arrival time**, so an event that happened Monday and was
   sent Wednesday is recorded as Wednesday, and `from`/`to` filters return it
   on the wrong day.
2. **There is one request per entry**, so flushing a queue of 200 events means
   200 HTTP requests from a phone.

## 1. Event time (`occurredAt`)

### Storage

Add an `occurred_at` column to both `logger_logs` and `logger_errors`:

- Type: same as the existing timestamp column (millisecond precision, UTC), `NOT NULL`.
- Migration: backfill `occurred_at = timestamp` for every existing row.
- Index: `(service, occurred_at)` on both tables. The queries below filter and sort on it.

The existing `timestamp` column keeps its meaning: the time the server received the entry.

### Reserved body keys

`occurredAt` and `sentAt` become reserved top-level keys, like `environment`.
They are removed from the stored `data`.

- Both are ISO-8601 strings. If there is no offset, the value is UTC (same rule as `from`/`to`).
- If either one is present but not a parseable string, respond 400.

### Clock-skew correction

Phone clocks can't be trusted, but the gap between two readings of the same clock can.
So when the client sends both values, the server keeps the *age* of the event
and ignores the absolute value:

```
occurred_at = receivedAt - (sentAt - occurredAt)
```

| Client sent | `occurred_at` stored |
|---|---|
| neither | `receivedAt` (today's behaviour) |
| `occurredAt` only | `occurredAt` as given (no correction possible) |
| `occurredAt` and `sentAt` | corrected using the formula above |
| `sentAt` only | `receivedAt` |

Clamps, applied after the correction:

- If the age (`sentAt - occurredAt`) is negative, use 0, so `occurred_at = receivedAt`.
- `occurred_at` is never later than `receivedAt`.

### Single-entry routes

`POST /log` and `POST /error` accept the optional `occurredAt` and `sentAt`
and apply the rules above. Nothing else changes.

## 2. Batch routes

### `POST /log/batch` and `POST /error/batch`

Same auth as `POST /log` and `POST /error`: a bearer token from an *active*
service, otherwise 401/403. `/log/batch` writes to `logger_logs` and
`/error/batch` writes to `logger_errors`.

```json
{
  "environment": "production",
  "sentAt": "2026-10-07T14:03:11Z",
  "entries": [
    {"occurredAt": "2026-10-05T09:12:40Z", "event": "game_start", "mode": "classic"},
    {"occurredAt": "2026-10-05T09:31:02Z", "event": "game_over", "score": 18432}
  ]
}
```

| Field | Rules |
|---|---|
| `environment` | Same validation as the single routes, default `unknown`. Applies to every entry. |
| `sentAt` | **Required.** ISO-8601. It is the reference for the skew correction of every entry. |
| `entries` | **Required.** A non-empty JSON array of 1–100 items. |
| each entry | A JSON object. `occurredAt` is optional and goes through the skew rules above; if it is missing, the entry's `occurred_at` is `receivedAt`. Everything else becomes the entry's `data`. An empty object is stored as `{}`. |

Validation, which gives a 400 and stores **nothing**:

- The body is not an object, `entries` is missing or not an array, or `entries` is empty.
- There are more than 100 entries.
- Any entry is not a JSON object (array, scalar, or null).
- Any entry contains `environment` or `sentAt`, which are batch-level only.
- `environment`, `sentAt`, or any entry's `occurredAt` is invalid.

The error `message` names the first offending index, e.g. `entries[3]: must be an object`.

A body over **256 KB** gets **413** in the standard error envelope.

**All or nothing:** validate every entry first, then insert all of them in one
transaction. If any insert fails, roll back and respond 500. The client relies
on this: it resends the whole batch after a 5xx or a network failure, and drops
it after a 4xx.

All entries in the batch share the same `receivedAt` (one clock reading per
request) and so the same `timestamp`.

Response (HTTP 200):

```json
{"success": true, "data": {"count": 2}}
```

The response does not include ids. Batch clients don't use them.

## 3. Reading

These rules apply to `/report`, `/errors`, `/admin/logs` and `/admin/errors`.

- Each entry gains an `occurredAt` field (ISO-8601 UTC, ms, same format as `timestamp`).
  `timestamp` stays and still means the arrival time.
- `from`, `to` and the "newest first" order now use **`occurred_at`**.
  Existing rows are backfilled and entries without a client time get
  `occurred_at = timestamp`, so today's clients see the same results.

The following stay on `timestamp` (arrival time), since they are about the service and
storage rather than about the events:

- `lastLog` in `/admin/services`.
- `olderThanDays` in the `DELETE /admin/*` purges.

## 4. Deliverables

- A migration that adds `occurred_at`, backfills it, and adds the indexes on both tables.
- The skew logic in a single function, shared by the single and batch routes.
- The batch routes, with the size limits as named constants.
- Tests:
  - every row of the skew table, including the negative-age clamp and the future clamp;
  - batch validation (each 400 case, the 413 case, and that a rejected batch stores no rows);
  - a rollback on insert failure;
  - an existing-client regression test: `POST /log` without the new keys behaves exactly as in 1.2.
- An update to `openapi.yaml` (new routes, new reserved keys, `occurredAt` in `Entry`,
  and the `from`/`to` semantics) and to the `logger-api` skill.
- Version bumped to `1.3.0`.

## Out of scope

- Deduplication or idempotency keys. A batch sent twice, because its response
  was lost, is stored twice, and that's acceptable for this use.
- Gzip request bodies.
- Batch reads or batch purges.
