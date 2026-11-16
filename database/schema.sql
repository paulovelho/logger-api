-- Logger storage. ⚠️ DROPs both tables first: running this wipes every log and error.
-- `timestamp` is when the server received the entry (filled by the logger, in magrathea.conf's `timezone`); `occurred_at` is when
-- the event happened (client time, skew-corrected; = `timestamp` when the client sends none).
-- `data` is whatever JSON the service posted, minus the reserved keys (environment, occurredAt, sentAt).
-- `created_at` / `updated_at`: MagratheaPHP 3's bookkeeping, filled by the database, never read or returned by the API.
-- Existing databases: run the scripts in migrations/ (1.2.2-occurred-at.sql, 1.3.0-timestamps.sql) instead to keep the data.

DROP TABLE IF EXISTS logger_logs;
CREATE TABLE IF NOT EXISTS logger_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  occurred_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_timestamp (service, timestamp),
  INDEX idx_service_occurred_at (service, occurred_at)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS logger_errors;
CREATE TABLE IF NOT EXISTS logger_errors (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  occurred_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_timestamp (service, timestamp),
  INDEX idx_service_occurred_at (service, occurred_at)
) ENGINE=InnoDB;
