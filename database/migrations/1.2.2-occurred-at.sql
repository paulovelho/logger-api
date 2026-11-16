-- 1.2.2: event time (`occurred_at`) next to the arrival time (`timestamp`), both with milliseconds.
-- Re-runnable on MariaDB and MySQL: the column is added nullable, backfilled where still NULL, then
-- made NOT NULL, so a second run changes nothing. The column and the index are only added when
-- information_schema doesn't list them yet (`ADD ... IF NOT EXISTS` is MariaDB-only).
-- Batch writes rely on InnoDB (a multi-row INSERT is atomic).
--   mysql -u <user> -p <database> < database/migrations/1.2.2-occurred-at.sql

-- logger_logs
SELECT IF(COUNT(*) = 0, ', ADD COLUMN occurred_at DATETIME(3) NULL AFTER timestamp', '') INTO @add
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_logs' AND COLUMN_NAME = 'occurred_at';
SET @sql = CONCAT('ALTER TABLE logger_logs ENGINE = InnoDB, ',
  'MODIFY timestamp DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)', @add);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE logger_logs SET occurred_at = timestamp WHERE occurred_at IS NULL;

SELECT IF(COUNT(*) = 0, ', ADD INDEX idx_service_occurred_at (service, occurred_at)', '') INTO @add
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_logs' AND INDEX_NAME = 'idx_service_occurred_at';
SET @sql = CONCAT('ALTER TABLE logger_logs ',
  'MODIFY occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)', @add);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- logger_errors
SELECT IF(COUNT(*) = 0, ', ADD COLUMN occurred_at DATETIME(3) NULL AFTER timestamp', '') INTO @add
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_errors' AND COLUMN_NAME = 'occurred_at';
SET @sql = CONCAT('ALTER TABLE logger_errors ENGINE = InnoDB, ',
  'MODIFY timestamp DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)', @add);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE logger_errors SET occurred_at = timestamp WHERE occurred_at IS NULL;

SELECT IF(COUNT(*) = 0, ', ADD INDEX idx_service_occurred_at (service, occurred_at)', '') INTO @add
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logger_errors' AND INDEX_NAME = 'idx_service_occurred_at';
SET @sql = CONCAT('ALTER TABLE logger_errors ',
  'MODIFY occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)', @add);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
