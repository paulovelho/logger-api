CREATE TABLE IF NOT EXISTS logger_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_service_timestamp (service, timestamp)
);

CREATE TABLE IF NOT EXISTS logger_errors (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_service_timestamp (service, timestamp)
);
