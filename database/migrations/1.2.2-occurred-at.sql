-- 1.2.2: event time (`occurred_at`) next to the arrival time (`timestamp`), both with milliseconds.
-- Re-runnable: the column is added nullable, backfilled where still NULL, then made NOT NULL,
-- so a second run changes nothing. Batch writes rely on InnoDB (a multi-row INSERT is atomic).
--   mariadb -u <user> -p <database> < database/migrations/1.2.2-occurred-at.sql

ALTER TABLE logger_logs
  ENGINE = InnoDB,
  MODIFY timestamp DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ADD COLUMN IF NOT EXISTS occurred_at DATETIME(3) NULL AFTER timestamp;
UPDATE logger_logs SET occurred_at = timestamp WHERE occurred_at IS NULL;
ALTER TABLE logger_logs
  MODIFY occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ADD INDEX IF NOT EXISTS idx_service_occurred_at (service, occurred_at);

ALTER TABLE logger_errors
  ENGINE = InnoDB,
  MODIFY timestamp DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ADD COLUMN IF NOT EXISTS occurred_at DATETIME(3) NULL AFTER timestamp;
UPDATE logger_errors SET occurred_at = timestamp WHERE occurred_at IS NULL;
ALTER TABLE logger_errors
  MODIFY occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ADD INDEX IF NOT EXISTS idx_service_occurred_at (service, occurred_at);
