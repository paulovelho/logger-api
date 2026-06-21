# Logger — MongoDB → MariaDB Migration Plan

## Goal

Replace MongoDB/Mongoose with MariaDB in the logger microservice. No changes to the external API contract — all endpoints keep the same paths, request/response shapes, and auth behavior.

---

## Current Stack

- Node.js + Express (plain JS, no TypeScript, no build step)
- Mongoose (MongoDB ODM)
- `docker-compose.yml` runs two containers: `api` + `mongo:7`
- `.env` has `MONGO_URI`, `JWT_SECRET`, `PORT`
- `config.json` holds service user credentials (static, not in DB)

---

## New Stack

- Same Node.js + Express
- Replace `mongoose` → `mysql2` (promise API)
- MariaDB shared from the existing `guialolnetwork` Docker network (same DB host as `api/`)
- `.env` changes: remove `MONGO_URI`, add `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`

---

## Database Schema

New table in the shared MariaDB instance. Add to `docker/database/api-structure.sql`:

```sql
CREATE TABLE logger_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    VARCHAR(100) NOT NULL,
  data       JSON         NOT NULL,
  timestamp  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_timestamp (user_id, timestamp)
);
```

`data JSON` preserves the current "arbitrary payload" behavior — no schema enforcement on log content.

---

## File-by-File Changes

### `src/index.js`
- Remove `mongoose.connect(...)` startup block
- Add MariaDB connection pool init (via `mysql2/promise`)
- Export pool so routes can import it
- Keep everything else identical

### `src/models/Log.js` → `src/db.js`
- Delete the Mongoose model
- Create a `db.js` that exports the `mysql2` connection pool
- All queries move into route files directly (no ORM)

### `src/middleware/auth.js`
- No changes — JWT logic is DB-independent

### `src/routes/auth.js`
- No changes — reads from `config.json`, no DB calls

### `src/routes/log.js`
Replace Mongoose `Log.create()` with:
```js
const [result] = await pool.execute(
  'INSERT INTO logger_logs (user_id, data) VALUES (?, ?)',
  [req.userId, JSON.stringify(req.body)]
);
res.status(201).json({ id: result.insertId, timestamp: new Date() });
```

### `src/routes/report.js`
Replace Mongoose `Log.find()` + `countDocuments()` with parameterized SQL:
```sql
SELECT id, user_id, data, timestamp
FROM logger_logs
WHERE user_id = ?
  AND (? IS NULL OR timestamp >= ?)
  AND (? IS NULL OR timestamp <= ?)
ORDER BY timestamp DESC
LIMIT ? OFFSET ?
```
Returns same JSON shape: `{ total, count, logs }`.
Each log row: `{ _id: row.id, userId: row.user_id, data: JSON.parse(row.data), timestamp: row.timestamp }` — keep `_id` key to avoid breaking callers.

### `src/routes/admin.js`
- `GET /admin/logs` — same parameterized query as report, minus the userId filter restriction
- `GET /admin/services` — replace MongoDB aggregation with:
```sql
SELECT user_id AS userId, COUNT(*) AS count, MAX(timestamp) AS lastLog
FROM logger_logs
GROUP BY user_id
ORDER BY lastLog DESC
```

### `docker-compose.yml`
- Remove `mongo:7` service
- Remove MongoDB volume
- Add connection to `guialolnetwork` so the logger container can reach the shared MariaDB
- Update env vars

### `.env` / `.env.example`
- Remove: `MONGO_URI`
- Add: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`

### `package.json`
- Remove: `mongoose`
- Add: `mysql2`

---

## Response Shape Compatibility

Current `POST /log` response uses `entry._id` (MongoDB ObjectId). After migration, use `result.insertId` (integer) under the same `id` key. Callers (crawler) only use this for confirmation — no downstream effect expected.

Current log objects in `GET /report` and `GET /admin/logs` use `_id`. Keep mapping SQL `id` → `_id` in the response to avoid breaking the admin UI or any existing callers.

---

## Migration Steps (in order)

1. Add `logger_logs` table to `docker/database/api-structure.sql`
2. Run the new table creation on the running MariaDB instance
3. Update `package.json`: remove `mongoose`, add `mysql2`; run `npm install`
4. Create `src/db.js` with the `mysql2` connection pool
5. Update `src/routes/log.js`
6. Update `src/routes/report.js`
7. Update `src/routes/admin.js`
8. Update `src/index.js` — remove Mongoose startup, import pool
9. Update `docker-compose.yml` — drop mongo, join guialolnetwork
10. Update `.env.example`
11. Test all endpoints manually (POST /log, GET /report, GET /admin/logs, GET /admin/services)
12. Delete `src/models/Log.js`

---

## Out of Scope

- TypeScript migration (keep plain JS)
- Adding auth to `/admin` endpoints (already unprotected, separate concern)
- Data migration from existing MongoDB logs (old logs are disposable)
- Test suite (none exists today)
