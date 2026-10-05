-- Logger storage. Run against each instance's own database.
-- `data` is whatever JSON the service posted; `timestamp` is UTC with milliseconds.

CREATE TABLE IF NOT EXISTS `logs` (
	`id`        CHAR(36)    NOT NULL,
	`user_id`   VARCHAR(64) NOT NULL,
	`data`      JSON        NOT NULL,
	`timestamp` DATETIME(3) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_user_ts` (`user_id`, `timestamp`),
	KEY `idx_ts` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
