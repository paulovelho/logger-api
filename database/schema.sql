CREATE TABLE IF NOT EXISTS logger_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  service     VARCHAR(100) NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_timestamp (user_id, timestamp),
  INDEX idx_service (service)
);

CREATE TABLE IF NOT EXISTS logger_errors (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     VARCHAR(100) NOT NULL,
  environment VARCHAR(50)  NOT NULL DEFAULT 'unknown',
  service     VARCHAR(100) NOT NULL DEFAULT 'unknown',
  data        JSON         NOT NULL,
  timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_timestamp (user_id, timestamp),
  INDEX idx_service (service)
);
