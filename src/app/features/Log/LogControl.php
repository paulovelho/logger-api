<?php
namespace logger\Log;

use logger\ErrorLog\ErrorLog;
use logger\ServiceUsers;
use Magrathea2\DB\Database;
use Magrathea2\Exceptions\MagratheaApiException;

/**
 * Reads, writes and purges for both tables (`logger_logs`, `logger_errors`: same columns).
 * Every input is validated and re-formatted here before it reaches SQL: Query::Clean is
 * too weak to rely on, and PrepareAndExecute() can't return rows.
 */
class LogControl extends \logger\Log\Base\LogControlBase {

	const LOGS = "logger_logs";
	const ERRORS = "logger_errors";
	/** Service the purge audit rows are written under (not a config.json credential). */
	const SELF_SERVICE = "logger";
	const SELF_NAME = "Logger (self)";

	const MAX_LIMIT = 1000;
	const DEFAULT_LIMIT = 100;
	const DEFAULT_PURGE_DAYS = 365;
	/** POST /log/batch, /error/batch limits. */
	const MAX_BATCH_ENTRIES = 100;
	const MAX_BATCH_BYTES = 262144; // 256 KB
	/** Earliest value a DATETIME column takes. */
	const DATETIME_MIN = "1000-01-01 00:00:00";

	private static function Table(string $table): string {
		if ($table !== self::LOGS && $table !== self::ERRORS) {
			throw new MagratheaApiException("Unknown table '".$table."'", 500);
		}
		return $table;
	}

	/** Same width as the `service` column (VARCHAR(100)). */
	public static function ValidService(string $service): bool {
		return preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $service) === 1;
	}

	/**
	 * Stores one entry (POST /log, /error): the reserved keys `environment` (default 'unknown'),
	 * `occurredAt` and `sentAt` are pulled out of the body and the rest is stored as `data`.
	 * @return array{id:int, timestamp:string}
	 */
	public static function Write(string $table, string $service, \stdClass $body): array {
		$table = self::Table($table);
		$environment = self::Environment($body);
		$occurredAt = property_exists($body, "occurredAt") ? self::ClientDate($body->occurredAt, "occurredAt") : null;
		$sentAt = property_exists($body, "sentAt") ? self::ClientDate($body->sentAt, "sentAt") : null;
		unset($body->environment, $body->occurredAt, $body->sentAt);
		$receivedAt = self::ReceivedAt();
		$occurred = self::InRange(self::OccurredAt($receivedAt, $occurredAt, $sentAt), "occurredAt");
		$id = self::InsertRows($table, [ self::Row($service, $environment, $body, $receivedAt, $occurred) ]);
		return [ "id" => $id, "timestamp" => self::Iso($receivedAt) ];
	}

	/**
	 * Stores a batch (POST /log/batch, /error/batch) all or nothing: every entry is validated first,
	 * then all of them go into one multi-row INSERT (a single statement, so InnoDB rolls back
	 * every row if any fails). `environment` and `sentAt` are batch-level; each entry may carry
	 * its own `occurredAt`. All rows share one `receivedAt`.
	 * @return array{count:int}
	 */
	public static function WriteBatch(string $table, string $service, \stdClass $body): array {
		$table = self::Table($table);
		$entries = $body->entries ?? null;
		if (!is_array($entries) || count($entries) === 0) {
			throw new MagratheaApiException("'entries' must be a non-empty array", 400);
		}
		if (count($entries) > self::MAX_BATCH_ENTRIES) {
			throw new MagratheaApiException("'entries' can't have more than ".self::MAX_BATCH_ENTRIES." items", 400);
		}
		$environment = self::Environment($body);
		if (!property_exists($body, "sentAt")) throw new MagratheaApiException("'sentAt' is required", 400);
		$sentAt = self::ClientDate($body->sentAt, "sentAt");

		$parsed = [];
		foreach ($entries as $i => $entry) {
			$at = "entries[".$i."]: ";
			if (!($entry instanceof \stdClass)) throw new MagratheaApiException($at."must be an object", 400);
			foreach ([ "environment", "sentAt" ] as $key) {
				if (property_exists($entry, $key)) {
					throw new MagratheaApiException($at."'".$key."' is batch-level only", 400);
				}
			}
			$occurredAt = null;
			if (property_exists($entry, "occurredAt")) {
				$occurredAt = self::ClientDate($entry->occurredAt, "occurredAt", $at);
				unset($entry->occurredAt);
			}
			$parsed[] = [ $entry, $occurredAt ];
		}

		$receivedAt = self::ReceivedAt();
		$rows = [];
		foreach ($parsed as $i => [ $data, $occurredAt ]) {
			$occurred = self::InRange(self::OccurredAt($receivedAt, $occurredAt, $sentAt), "occurredAt", "entries[".$i."]: ");
			$rows[] = self::Row($service, $environment, $data, $receivedAt, $occurred);
		}
		self::InsertRows($table, $rows);
		return [ "count" => count($rows) ];
	}

	/**
	 * When the event happened, from the client's clock readings. Only the gap between the client's
	 * `sentAt` and `occurredAt` (the event's age) is trusted, so with both the result is
	 * `receivedAt - (sentAt - occurredAt)`; a negative age counts as 0. With only `occurredAt` it is
	 * kept as given; without it, the event happened on arrival. Never later than `receivedAt`.
	 */
	public static function OccurredAt(\DateTimeImmutable $receivedAt, ?\DateTimeImmutable $occurredAt, ?\DateTimeImmutable $sentAt): \DateTimeImmutable {
		if ($occurredAt === null) return $receivedAt;
		if ($sentAt !== null) {
			$age = max(0, self::Micros($sentAt) - self::Micros($occurredAt));
			$occurredAt = self::FromMicros(self::Micros($receivedAt) - $age);
		}
		return $occurredAt > $receivedAt ? $receivedAt : $occurredAt;
	}

	/**
	 * A client date (`occurredAt`, `sentAt`): an ISO-8601 string, UTC unless it has an offset.
	 * Stricter than `from`/`to`: relative formats ("now", "tomorrow") are not accepted.
	 */
	public static function ClientDate($value, string $name, string $at = ""): \DateTimeImmutable {
		$invalid = new MagratheaApiException($at."Invalid '".$name."': must be an ISO-8601 date string", 400);
		if (!is_string($value)) throw $invalid;
		$iso = '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}([T ][0-9]{2}:[0-9]{2}(:[0-9]{2}(\.[0-9]{1,6})?)?(Z|[+-][0-9]{2}(:?[0-9]{2})?)?)?$/i';
		if (preg_match($iso, $value) !== 1) throw $invalid;
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone("UTC"));
		} catch (\Exception $ex) {
			throw $invalid;
		}
		// rolled-over dates (Feb 30, 25:00) only produce warnings
		$errors = \DateTimeImmutable::getLastErrors();
		if ($errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0)) throw $invalid;
		return $date->setTimezone(new \DateTimeZone("UTC"));
	}

	/** `environment`: a string ≤ 50 chars or a number; absent or null → 'unknown'. */
	private static function Environment(\stdClass $body): string {
		$environment = $body->environment ?? "unknown";
		if (is_int($environment) || is_float($environment)) $environment = (string)$environment;
		if (!is_string($environment) || $environment === "" || mb_strlen($environment) > 50) {
			throw new MagratheaApiException("Invalid 'environment'", 400);
		}
		return $environment;
	}

	/** One clock reading per request, from the same clock that filled `timestamp` (and purges compare against). */
	private static function ReceivedAt(): \DateTimeImmutable {
		$now = Database::Instance()->QueryOne("SELECT DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f')");
		if (!is_string($now)) throw new MagratheaApiException("Failed to read the server time", 500);
		return new \DateTimeImmutable($now, new \DateTimeZone("UTC"));
	}

	/** Keeps `occurred_at` inside DATETIME's range (a huge age can push it before year 1000). */
	private static function InRange(\DateTimeImmutable $date, string $name, string $at = ""): \DateTimeImmutable {
		if ($date < new \DateTimeImmutable(self::DATETIME_MIN, new \DateTimeZone("UTC"))) {
			throw new MagratheaApiException($at."'".$name."' is out of range", 400);
		}
		return $date;
	}

	private static function Micros(\DateTimeImmutable $date): int {
		return $date->getTimestamp() * 1000000 + (int)$date->format("u");
	}

	private static function FromMicros(int $micros): \DateTimeImmutable {
		$seconds = intdiv($micros, 1000000);
		$rest = $micros % 1000000;
		if ($rest < 0) { $seconds--; $rest += 1000000; }
		$date = (new \DateTimeImmutable("@".$seconds))->setTimezone(new \DateTimeZone("UTC"));
		return $date->setTime((int)$date->format("G"), (int)$date->format("i"), (int)$date->format("s"), $rest);
	}

	/** @return array{service:string, environment:string, data:string, timestamp:string, occurred_at:string} */
	private static function Row(string $service, string $environment, \stdClass $data, \DateTimeImmutable $receivedAt, \DateTimeImmutable $occurredAt): array {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) throw new MagratheaApiException("Invalid data: ".json_last_error_msg(), 400);
		return [
			"service" => $service,
			"environment" => $environment,
			"data" => $json,
			"timestamp" => self::Sql($receivedAt),
			"occurred_at" => self::Sql($occurredAt),
		];
	}

	/**
	 * Inserts rows (Row() shape) with one multi-row INSERT, so either all of them are stored or none.
	 * PrepareAndExecute() swallows some statement errors (returns null) and echoes "got error!" while
	 * doing it; the echo is discarded so it can't corrupt the JSON response.
	 * @return int the first row's id
	 */
	public static function InsertRows(string $table, array $rows): int {
		$table = self::Table($table);
		$columns = [ "service", "environment", "data", "timestamp", "occurred_at" ];
		$tuple = "(".implode(", ", array_fill(0, count($columns), "?")).")";
		$sql = "INSERT INTO `".$table."` (`".implode("`, `", $columns)."`) VALUES "
			.implode(", ", array_fill(0, count($rows), $tuple));
		$values = [];
		foreach ($rows as $row) {
			foreach ($columns as $column) $values[] = $row[$column];
		}
		ob_start();
		try {
			$id = Database::Instance()->PrepareAndExecute($sql, array_fill(0, count($values), "string"), $values);
		} finally {
			ob_end_clean();
		}
		if (!is_int($id) || $id <= 0) {
			throw new MagratheaApiException($table === self::ERRORS ? "Failed to save error" : "Failed to save log", 500);
		}
		return $id;
	}

	/** UTC `Y-m-d H:i:s.v`, for the DATETIME(3) columns. */
	private static function Sql(\DateTimeImmutable $date): string {
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s.v");
	}

	private static function Iso(\DateTimeImmutable $date): string {
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.v\Z");
	}

	/** Parses any date string DateTimeImmutable accepts and returns it as UTC `Y-m-d H:i:s.v`. */
	private static function SqlDate(string $value, string $name): string {
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone("UTC"));
		} catch (\Exception $ex) {
			throw new MagratheaApiException("Invalid '".$name."' date", 400);
		}
		return self::Sql($date);
	}

	private static function IntParam(?string $value, int $default, string $name): int {
		if ($value === null) return $default;
		if (preg_match('/^-?[0-9]{1,9}$/', $value) !== 1) {
			throw new MagratheaApiException("Invalid '".$name."'", 400);
		}
		return (int)$value;
	}

	/** Reads a query-string value; rejects arrays (`?limit[]=1`). Empty string counts as absent. */
	public static function Param(array $query, string $name): ?string {
		$value = $query[$name] ?? null;
		if ($value === null || $value === "") return null;
		if (!is_string($value)) throw new MagratheaApiException("Invalid '".$name."'", 400);
		return $value;
	}

	/**
	 * @param string      $table    LOGS or ERRORS
	 * @param string|null $service  restricts to one service; null = every service (admin)
	 * @param array       $query    from, to (any date DateTimeImmutable parses; on `occurred_at`), limit (1–1000), skip (≥0)
	 * @return array{total:int, count:int, logs|errors:array}
	 */
	public static function Search(string $table, ?string $service, array $query): array {
		$table = self::Table($table);
		$from = self::Param($query, "from");
		$to = self::Param($query, "to");
		$where = [];
		if ($service !== null && $service !== "") {
			if (!self::ValidService($service)) throw new MagratheaApiException("Invalid 'service'", 400);
			$where[] = "`service` = '".$service."'";
		}
		// event time, not arrival time: equal unless the client sent `occurredAt`
		if ($from !== null) $where[] = "`occurred_at` >= '".self::SqlDate($from, "from")."'";
		if ($to !== null) $where[] = "`occurred_at` <= '".self::SqlDate($to, "to")."'";

		$limit = self::IntParam(self::Param($query, "limit"), self::DEFAULT_LIMIT, "limit");
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$skip = self::IntParam(self::Param($query, "skip"), 0, "skip");
		if ($skip < 0) throw new MagratheaApiException("Invalid 'skip'", 400);

		$whereSql = count($where) ? " WHERE ".implode(" AND ", $where) : "";
		$db = Database::Instance();
		$total = (int)$db->QueryOne("SELECT COUNT(*) FROM `".$table."`".$whereSql);
		$rows = $db->QueryAll(
			"SELECT `id`, `service`, `environment`, `data`, `timestamp`, `occurred_at` FROM `".$table."`".$whereSql
			." ORDER BY `occurred_at` DESC, `id` DESC LIMIT ".$limit." OFFSET ".$skip
		);
		$entries = array_map(fn($r) => self::FormatRow((array)$r), $rows);
		return [
			"total" => $total,
			"count" => count($entries),
			($table === self::ERRORS ? "errors" : "logs") => $entries,
		];
	}

	/**
	 * Node's /admin/services: every active config.json service (even with no logs) plus the
	 * synthetic Logger (self), with counts from `logger_logs` only; most recently active first.
	 * (snake_case alias: Database lower-cases column names.)
	 * @return array<array{service:string, name:string, count:int, lastLog:?string}>
	 */
	public static function Services(): array {
		$rows = Database::Instance()->QueryAll(
			"SELECT `service`, COUNT(*) AS `count`, MAX(`timestamp`) AS `last_log`"
			." FROM `".self::LOGS."` GROUP BY `service`"
		);
		$stats = [];
		foreach ($rows as $r) {
			$r = (array)$r;
			$stats[$r["service"]] = $r;
		}
		$services = ServiceUsers::Active();
		$services[] = [ "service" => self::SELF_SERVICE, "name" => self::SELF_NAME ];
		$services = array_map(fn($s) => [
			"service" => $s["service"],
			"name" => $s["name"],
			"count" => (int)($stats[$s["service"]]["count"] ?? 0),
			"lastLog" => self::IsoDate($stats[$s["service"]]["last_log"] ?? null),
		], $services);
		// ISO strings sort chronologically; null (never logged) goes last
		usort($services, fn($a, $b) => strcmp($b["lastLog"] ?? "", $a["lastLog"] ?? ""));
		return $services;
	}

	/**
	 * Deletes `service`'s rows older than `days` days, then writes a self-audit row into
	 * `logger_logs` under SELF_SERVICE. PrepareAndExecute() doesn't expose affected rows, so the
	 * rows are counted first, against a cutoff computed once so both queries see the same instant.
	 * @return array{deleted:int}
	 */
	public static function Purge(string $table, string $service, int $days, string $performedBy): array {
		$table = self::Table($table);
		if (!self::ValidService($service)) throw new MagratheaApiException("Invalid 'service'", 400);
		$db = Database::Instance();
		$cutoff = $db->QueryOne("SELECT DATE_FORMAT(NOW() - INTERVAL ".$days." DAY, '%Y-%m-%d %H:%i:%s')");
		if (!is_string($cutoff)) throw new MagratheaApiException("Failed to purge", 500);
		$whereSql = " WHERE `service` = '".$service."' AND `timestamp` < '".$cutoff."'";
		$deleted = (int)$db->QueryOne("SELECT COUNT(*) FROM `".$table."`".$whereSql);
		if ($deleted > 0 && $db->Query("DELETE FROM `".$table."`".$whereSql) === false) {
			throw new MagratheaApiException("Failed to purge", 500);
		}
		self::Write(self::LOGS, self::SELF_SERVICE, (object)[
			"environment" => "unknown",
			"action" => $table === self::ERRORS ? "purge_errors" : "purge_logs",
			"targetService" => $service,
			"cutoffDays" => $days,
			"deletedCount" => $deleted,
			"performedBy" => $performedBy,
		]);
		return [ "deleted" => $deleted ];
	}

	/** Node's row shape. */
	public static function FormatRow(array $row): array {
		return [
			"_id" => (int)$row["id"],
			"service" => $row["service"],
			"serviceName" => ServiceUsers::Name($row["service"]),
			"environment" => $row["environment"],
			"data" => json_decode($row["data"] ?? "null"),
			"timestamp" => self::IsoDate($row["timestamp"]),
			"occurredAt" => self::IsoDate($row["occurred_at"] ?? $row["timestamp"]),
		];
	}

	/** `2026-10-05 12:00:00.123` (UTC, from DB) → `2026-10-05T12:00:00.123Z` */
	public static function IsoDate(?string $sqlDate): ?string {
		if ($sqlDate === null) return null;
		$date = new \DateTimeImmutable($sqlDate, new \DateTimeZone("UTC"));
		return $date->format("Y-m-d\TH:i:s.v\Z");
	}
}
