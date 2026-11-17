# Plan: the dashboard's `cleaner` tab

A new tab on the logger's `/admin` dashboard (`src/app/admin.html|js|css`), placed between `services` and `tokens`. It deletes one service's logs **or** errors whose event time is before a chosen date. Before anything is deleted, it shows how many entries will go and waits for a confirmation.

## Decisions (agreed)

- **Service:** the top-bar filter. With `all` selected, the cleaner is disabled and asks you to pick a service. The API keeps `service` required, so there's no way to purge every service at once.
- **Date:** event time (`occurred_at`, the `occurredAt` the lists show and filter on), not arrival time. The existing `olderThanDays` purge keeps using arrival time (`timestamp`), so the retention scripts in `logger-deletion.md` and the Angular admin's `purgeLogs/purgeErrors` don't change.
- **Version:** goes into the unreleased 1.3.0 changelog entry. No version bump.

## API

### Preview: new routes

`GET /admin/logs/purge?service=<s>&occurredBefore=<iso>` (and the same for `/admin/errors/purge`). Admin only.

→ `{count, occurredBefore, maxId}`

- `count`: how many rows the DELETE would remove right now.
- `occurredBefore`: the cutoff, normalised to `…Z`.
- `maxId`: the highest `id` that matches (`null` when `count` is 0). The confirmation sends it back (see below).

A preview route keeps the count and the delete on the **same WHERE builder**, so the count can't drift from what gets deleted. The other options were a `?to=` list query, which is inclusive (`<=`) and paginated, or a DELETE `dryRun`, a DELETE that doesn't delete. The `/admin/logs/purge` path sits next to `admin/logs` the way `log/batch` sits next to `log`. The vendor's router matches segment by segment.

### DELETE: one new mode on the existing routes

`DELETE /admin/logs?service=<s>&occurredBefore=<iso>[&maxId=<n>]`

- `occurredBefore` and `olderThanDays` are mutually exclusive: sending both is a 400. Sending neither keeps today's default (365 days, on `timestamp`).
- `occurredBefore` is a strict ISO-8601 date (`LogControl::ClientDate`, the same parser as `occurredAt`). A date in the future is a 400: `occurred_at` is never later than its arrival, so a future cutoff would mean "everything, including entries arriving now".
- `maxId` (optional, a positive integer): only rows with `id <= maxId` are deleted. **Why:** with event time, a late-synced offline batch (the LÖVE2D game) can add rows below the cutoff between the preview and the click. With `maxId`, the number deleted is never more than the number shown. It can be fewer if someone else purged in between. The response always reports the real `deleted`.
- Response unchanged: `{deleted}`.

**Audit row** (written under `logger`, as today): `{action, targetService, occurredBefore, maxId, deletedCount, performedBy}` for this mode. Days mode keeps `cutoffDays` exactly as it is now, so existing audit consumers and `PurgeTest` keep working.

### Code

- `LogControl`:
  - `private static PurgeWhere(service, ?days, ?occurredBefore, ?maxId): string` is the only place the purge WHERE is built. It validates the service, then uses `timestamp < cutoff` for days or `occurred_at < cutoff` for a date, plus `AND id <= maxId`.
  - `Purge()` gets a cutoff argument that is either days or a date (signature below) and uses `PurgeWhere`. Days mode produces the same SQL and audit row as now.
  - `PurgeCount(table, service, occurredBefore): array{count, occurredBefore, maxId}` runs `SELECT COUNT(*), MAX(id)` on the same WHERE. The `(service, occurred_at)` index covers it.
  - Signature sketch: `Purge(string $table, string $service, int|\DateTimeImmutable $cutoff, string $performedBy, ?int $maxId = null)`. An `int` means days on `timestamp`; a date means `occurred_at`.
  - The future-date check lives here (against `ReceivedAt()`), so the tests can hit it directly.
- `AdminApi`: `PurgeLogsPreview` / `PurgeErrorsPreview` (GET), and `Purge()` parses `occurredBefore` / `maxId` and rejects `occurredBefore` together with `olderThanDays`.
- `LoggerApi::Admin()`: two new `Add("GET", "admin/logs/purge", …)` / `admin/errors/purge` lines.

## Dashboard

**`admin.html`**: `<button class="tab" data-tab="cleaner">cleaner</button>` before `tokens`, plus a `#tab-cleaner` panel:

```
service: Crawler                         ← read-only, from the top bar
[ logs ▾ ]  occurred before [ 2026-11-01 ]  [check]

 1,234 logs from Crawler that occurred before 01/11/2026 will be deleted.
 This can't be undone.                    [delete 1,234 logs] [cancel]
```

- **`all` selected:** the controls are disabled and the panel says "Pick a service in the top bar. The cleaner works on one service at a time."
- **Date:** `<input type="date">`, `max` = today. The cutoff is the start of that day in the browser's timezone, sent as UTC ISO. "Before 1 Nov" keeps 1 Nov.
- **check:** calls `GET /admin/<table>/purge`. If `count` is 0, it shows "Nothing to delete" and no delete button. Otherwise it shows the message above, with the service's display name, the table and the date.
- **delete:** sends the DELETE with the preview's `occurredBefore` and `maxId`, then shows "Deleted N logs from Crawler." and refreshes the service cards' counts (`loadServices()`). The confirmation is in-page, never `confirm()`. The button disables itself while the request runs, so a double click can't fire twice.
- **Stale preview:** changing the service (top bar), the table or the date clears the preview, so a confirmation can never act on settings it didn't show. The top-bar `change` handler gets a `resetCleaner()` call.
- Errors use the same `showFailure` / `.error` style as the other tabs. Server messages are rendered as text.

**`admin.css`**: a `.danger` button style (red border and text, the `.error` colour `#f7768e`) and spacing for the confirmation box. Nothing else.

## Docs

- `src/app/openapi.yaml`: the two GET preview routes, and `occurredBefore` / `maxId` on both DELETEs (with the exclusivity and the future-date 400).
- `CLAUDE.md` (logger): endpoint table rows, a line under Key Design Decisions (purge has two modes: days on `timestamp`, `occurredBefore` on `occurred_at`; the preview shares the WHERE; `maxId`), and the `cleaner` tab in the structure notes.
- `.claude/skills/logger-api/SKILL.md`: preview route and the new DELETE params.
- `changelog.md`, under 1.3.0: a **new** line for the preview routes and `occurredBefore`/`maxId`, and an **admin** line for the cleaner tab.
- `CLAUDE.md` (root): no change. The logger's routes aren't listed there.

## Tests (`src/tests/PurgeTest.php`)

- An `occurredBefore` purge deletes by `occurred_at`, not `timestamp`. Seed rows with an old `occurred_at` and a recent `timestamp` (deleted), and the reverse (kept).
- `PurgeCount` returns the same number the following `Purge` deletes, and `maxId` is the highest matching id.
- `maxId` spares matching rows inserted after the preview.
- A future `occurredBefore` throws a 400. A count with no matches gives `{count: 0, maxId: null}`.
- Date-mode audit row shape. The existing days-mode tests stay as they are and must still pass (the regression check for the shared WHERE).
- Not covered by automated tests: AdminApi param parsing (`occurredBefore` + `olderThanDays` → 400, bad `maxId` → 400). These are thin, and the existing tests call `LogControl` directly because the routes need the admin credential. I'll check them with curl against the local container.

## Not in scope

- The Angular `admin` app: it keeps using `olderThanDays` and doesn't change.
- Purging every service at once, and a bulk "clean both logs and errors" button. Run the cleaner twice for that.
