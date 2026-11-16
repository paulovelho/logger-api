-- 1.3.0: `created_at` / `updated_at`, the columns MagratheaPHP 3 expects on every model table
-- (`vendor/bin/magrathea check` reports them missing as an ERROR). Framework bookkeeping: the
-- database fills them, the logger never writes them and the API never returns them. Rows that
-- already exist get the time this script runs (no backfill from `timestamp`).
-- Re-runnable (ADD COLUMN IF NOT EXISTS), and works with the 1.2.x code, so it can run before the
-- deploy. Needs ALTER (e.g. root); on a large table it can block writes while it runs.
--   mariadb -u root -p <database> < database/migrations/1.3.0-timestamps.sql

ALTER TABLE logger_logs
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE logger_errors
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
