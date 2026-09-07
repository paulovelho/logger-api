# Plan: delete-old-logs endpoint (logger)

Status: **proposed, not implemented**. First of two sequential plans — admin
UI (`admin/`) is a separate follow-up plan, written only after this one is
confirmed working.

## Why

Admin wants a way to purge old logs/errors for a specific service from
`admin.guia.lol`. Today `logger` has no delete capability at all — only
`POST /log`, `POST /error`, and read-only `GET` endpoints (including
`/admin/logs`, `/admin/errors`, `/admin/services`). This plan adds the
missing delete capability on the logger side only.

## Decisions (confirmed with Paulo)

- Logs and errors are purged **separately** — two endpoints, not one combined
  action. Each call targets one table for one service.
- Cutoff is **configurable per request** (`olderThanDays`), not hardcoded to
  7 — default remains 7 if omitted.
- Deletions are **audited**, but not via a new table — instead the logger
  writes an entry about the purge into its own `logger_logs` table, under a
  reserved `service` value of `'logger'`. The logger becomes a client of
  itself. Confirmed intentional and recursive: if someone later purges
  `service=logger`, that also clears out the older purge-audit entries — a
  self-cleaning audit trail bounded by its own retention, not a permanent
  record. No new table, no new schema migration.
- `service` is **required** on every purge call — no "purge all services"
  shortcut, to keep the blast radius of one call bounded to what the caller
  explicitly named.

## No schema changes

`database/schema.sql` is untouched — the audit trail lives in the existing
`logger_logs` table (see "Audit trail" below), so no new table is needed.

## Middleware: no rename

Kept as `src/middleware/require-readonly.js` / `requireReadonly` —
confirmed to stay as-is, matching `config.json`'s existing `readonly: true`
flag on the `guia-admin` user. The new `DELETE` routes gate on the same
`requireReadonly` check as the existing `GET /admin/*` routes; no new
middleware file, no config shape change.

(Note, for the record: I'd flagged that the name reads oddly once a
`DELETE` route sits behind it — `readonly` describes the token, not the
request. Raised once, decided against, not relitigating it further.)

## New routes (`src/routes/admin.js`)

```
DELETE /admin/logs?service=<svc>&olderThanDays=<n=7>
DELETE /admin/errors?service=<svc>&olderThanDays=<n=7>
```

Both behind `authenticate` + `requireReadonly`, same as the existing
`GET /admin/*` routes.

Validation:
- `service` required → 400 if missing.
- `olderThanDays` optional, default `7`; must parse to a positive finite
  number → 400 otherwise.

Logic (logs shown; errors is the same against `logger_errors` /
`action: 'purge_errors'`):

```js
router.delete('/logs', authenticate, requireReadonly, async (req, res) => {
  try {
    const { service, olderThanDays = 7 } = req.query;
    if (!service) return res.status(400).json({ error: 'service is required' });

    const days = Number(olderThanDays);
    if (!Number.isFinite(days) || days <= 0) {
      return res.status(400).json({ error: 'olderThanDays must be a positive number' });
    }

    const [result] = await pool.execute(
      `DELETE FROM logger_logs WHERE service = ? AND timestamp < (NOW() - INTERVAL ? DAY)`,
      [service, days]
    );

    await pool.execute(
      `INSERT INTO logger_logs (service, environment, data) VALUES ('logger', 'unknown', ?)`,
      [JSON.stringify({
        action: 'purge_logs',
        targetService: service,
        cutoffDays: days,
        deletedCount: result.affectedRows,
        performedBy: req.service,
      })]
    );

    res.json({ deleted: result.affectedRows });
  } catch (err) {
    res.status(500).json({ error: 'Failed to purge logs' });
  }
});
```

The `/admin/errors` purge route follows the same shape but deletes from
`logger_errors`; its audit entry still goes into `logger_logs` (not
`logger_errors`) under `service: 'logger'` with `action: 'purge_errors'` —
it's an informational event about an action taken, not an error, so it
belongs with the logs.

## Audit visibility — no new endpoint

Because the audit entries are just rows in `logger_logs`, they're already
visible through the existing `GET /admin/logs?service=logger` — no new
route needed.

## Existing route change: `GET /admin/services`

The service list is built purely from `config.json`'s `users` array, so
`'logger'` would otherwise never appear in it — a UI service picker built
off this endpoint would have no way to select it, even though it's a real
source of log rows. Rather than add a fake credential entry to
`config.json` (it's not a real integration, nothing should ever log in as
`'logger'`), the route itself gets a small change: append one synthetic
entry for `'logger'`, sourced from the same `byService` aggregate as
everything else, independent of `config.users`.

```js
router.get('/services', authenticate, requireReadonly, async (_req, res) => {
  try {
    const [rows] = await pool.execute(
      `SELECT service, COUNT(*) AS count, MAX(timestamp) AS lastLog
       FROM logger_logs
       GROUP BY service`
    );
    const byService = Object.fromEntries(rows.map((r) => [r.service, r]));

    const configured = config.users
      .filter((u) => u.active !== false)
      .map((u) => ({
        service: u.service,
        name: u.name || u.service,
        count: byService[u.service]?.count ?? 0,
        lastLog: byService[u.service]?.lastLog ?? null,
      }));

    const services = [
      ...configured,
      {
        service: 'logger',
        name: 'Logger (self)',
        count: byService['logger']?.count ?? 0,
        lastLog: byService['logger']?.lastLog ?? null,
      },
    ];

    services.sort((a, b) => (b.lastLog ?? '') < (a.lastLog ?? '') ? -1 : 1);
    res.json({ services });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch services' });
  }
});
```

This resolves the service-picker gap for free — any admin UI built against
`GET /admin/services` (present or future) will list `Logger (self)`
alongside real services without special-casing it client-side.

## Docs to update alongside the code

- `logger/CLAUDE.md` — add the two new endpoints to the API table, and note
  `'logger'` as a reserved `service` value used for self-audit entries (so a
  future session doesn't mistake it for a real integrated service or
  accidentally register a client under that name).
- `logger/openapi.yaml` — add `DELETE /admin/logs` and `DELETE /admin/errors`
  path definitions (follow the existing `GET /admin/logs` definition as a
  template for auth + query params).

## Out of scope for this plan

- Any `admin.guia.lol` UI change (button, confirmation dialog, wiring
  `logger.api.ts`) — separate plan, written after this one is confirmed
  working end-to-end (e.g. via curl/Postman against a real service+cutoff).
- Rate limiting or role gating beyond the existing single `guia-admin`
  token — not asked for, not adding it speculatively.
