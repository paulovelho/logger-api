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
	 * Stores one entry, like Node did: `environment` is pulled out of the body (default 'unknown')
	 * and the rest is stored as `data`; `timestamp` is left to the DB default.
	 * @return array{id:int, timestamp:string}
	 */
	public static function Write(string $table, string $service, \stdClass $body): array {
		$entry = self::Table($table) === self::ERRORS ? new ErrorLog() : new Log();
		$environment = $body->environment ?? "unknown";
		unset($body->environment);
		if (is_int($environment) || is_float($environment)) $environment = (string)$environment;
		if (!is_string($environment) || $environment === "" || mb_strlen($environment) > 50) {
			throw new MagratheaApiException("Invalid 'environment'", 400);
		}
		$entry->service = $service;
		$entry->environment = $environment;
		$entry->data = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		// PrepareAndExecute() swallows statement errors and returns null instead of the insert id
		$id = $entry->Insert();
		if (!is_int($id) || $id <= 0) {
			throw new MagratheaApiException($table === self::ERRORS ? "Failed to save error" : "Failed to save log", 500);
		}
		return [
			"id" => $id,
			"timestamp" => (new \DateTimeImmutable("now", new \DateTimeZone("UTC")))->format("Y-m-d\TH:i:s.v\Z"),
		];
	}

	/** Parses any date string DateTimeImmutable accepts and returns it as UTC `Y-m-d H:i:s`. */
	private static function SqlDate(string $value, string $name): string {
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone("UTC"));
		} catch (\Exception $ex) {
			throw new MagratheaApiException("Invalid '".$name."' date", 400);
		}
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s");
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
	 * @param array       $query    from, to (any date DateTimeImmutable parses), limit (1–1000), skip (≥0)
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
		if ($from !== null) $where[] = "`timestamp` >= '".self::SqlDate($from, "from")."'";
		if ($to !== null) $where[] = "`timestamp` <= '".self::SqlDate($to, "to")."'";

		$limit = self::IntParam(self::Param($query, "limit"), self::DEFAULT_LIMIT, "limit");
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$skip = self::IntParam(self::Param($query, "skip"), 0, "skip");
		if ($skip < 0) throw new MagratheaApiException("Invalid 'skip'", 400);

		$whereSql = count($where) ? " WHERE ".implode(" AND ", $where) : "";
		$db = Database::Instance();
		$total = (int)$db->QueryOne("SELECT COUNT(*) FROM `".$table."`".$whereSql);
		$rows = $db->QueryAll(
			"SELECT `id`, `service`, `environment`, `data`, `timestamp` FROM `".$table."`".$whereSql
			." ORDER BY `timestamp` DESC, `id` DESC LIMIT ".$limit." OFFSET ".$skip
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
		];
	}

	/** `2026-10-05 12:00:00` (UTC, from DB) → `2026-10-05T12:00:00.000Z` */
	public static function IsoDate(?string $sqlDate): ?string {
		if ($sqlDate === null) return null;
		$date = new \DateTimeImmutable($sqlDate, new \DateTimeZone("UTC"));
		return $date->format("Y-m-d\TH:i:s.v\Z");
	}
}
