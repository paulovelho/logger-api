CREATE TABLE IF NOT EXISTS logger_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    VARCHAR(100) NOT NULL,
  data       JSON         NOT NULL,
  timestamp  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_timestamp (user_id, timestamp)
);
