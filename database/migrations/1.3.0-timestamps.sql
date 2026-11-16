-- 1.3.0: `created_at` / `updated_at`, the columns MagratheaPHP 3 expects on every model table
-- (`vendor/bin/magrathea check` reports them missing as an ERROR). Framework bookkeeping: the
-- database fills them, the logger never writes them and the API never returns them. Rows that
-- already exist get the time this script runs (no backfill from `timestamp`).
-- Re-runnable on MariaDB and MySQL: each table's ALTER only adds the columns information_schema
-- doesn't list yet (`ADD COLUMN IF NOT EXISTS` is MariaDB-only), and becomes a no-op once both exist.
-- Works with the 1.2.x code, so it can run before the deploy. Needs ALTER (e.g. root); on a large
-- table it can block writes while it runs.
--   mysql -u root -p <database> < database/migrations/1.3.0-timestamps.sql

SELECT CONCAT_WS(', ',
    IF(COALESCE(SUM(COLUMN_NAME = 'created_at'), 0) = 0,
      'ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', NULL),
    IF(COALESCE(SUM(COLUMN_NAME = 'updated_at'), 0) = 0,
      'ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', NULL))
  INTO @add
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_logs';
SET @sql = IF(@add = '', 'DO 0', CONCAT('ALTER TABLE logger_logs ', @add));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT CONCAT_WS(', ',
    IF(COALESCE(SUM(COLUMN_NAME = 'created_at'), 0) = 0,
      'ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', NULL),
    IF(COALESCE(SUM(COLUMN_NAME = 'updated_at'), 0) = 0,
      'ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', NULL))
  INTO @add
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_errors';
SET @sql = IF(@add = '', 'DO 0', CONCAT('ALTER TABLE logger_errors ', @add));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
