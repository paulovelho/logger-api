<?php
namespace logger\Log;

use Magrathea2\DB\Database;
use Magrathea2\Exceptions\MagratheaApiException;

/**
 * Read queries over `logs`.
 * Every input is validated and re-formatted here before it reaches SQL: Query::Clean is
 * too weak to rely on, and PrepareAndExecute() can't return rows.
 */
class LogControl extends \logger\Log\Base\LogControlBase {

	const MAX_LIMIT = 1000;
	const DEFAULT_LIMIT = 100;

	public static function ValidUserId(string $userId): bool {
		return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $userId) === 1;
	}

	/** Parses any date string DateTimeImmutable accepts and returns it as UTC `Y-m-d H:i:s.v`. */
	private static function SqlDate(string $value, string $name): string {
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone("UTC"));
		} catch (\Exception $ex) {
			throw new MagratheaApiException("Invalid '".$name."' date", 400);
		}
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s.v");
	}

	private static function IntParam(?string $value, int $default, string $name): int {
		if ($value === null) return $default;
		if (preg_match('/^-?[0-9]{1,9}$/', $value) !== 1) {
			throw new MagratheaApiException("Invalid '".$name."'", 400);
		}
		return (int)$value;
	}

	/** Reads a query-string value; rejects arrays (`?limit[]=1`). Empty string counts as absent. */
	private static function Param(array $query, string $name): ?string {
		$value = $query[$name] ?? null;
		if ($value === null || $value === "") return null;
		if (!is_string($value)) throw new MagratheaApiException("Invalid '".$name."'", 400);
		return $value;
	}

	/**
	 * @param string|null $userId  restricts to one service; null = every service (admin)
	 * @param array       $query   from, to (any date DateTimeImmutable parses), limit (1–1000), skip (≥0)
	 * @return array{total:int, count:int, logs:array}
	 */
	public static function Search(?string $userId, array $query): array {
		$from = self::Param($query, "from");
		$to = self::Param($query, "to");
		$where = [];
		if ($userId !== null && $userId !== "") {
			if (!self::ValidUserId($userId)) throw new MagratheaApiException("Invalid 'userId'", 400);
			$where[] = "`user_id` = '".$userId."'";
		}
		if ($from !== null) $where[] = "`timestamp` >= '".self::SqlDate($from, "from")."'";
		if ($to !== null) $where[] = "`timestamp` <= '".self::SqlDate($to, "to")."'";

		$limit = self::IntParam(self::Param($query, "limit"), self::DEFAULT_LIMIT, "limit");
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$skip = self::IntParam(self::Param($query, "skip"), 0, "skip");
		if ($skip < 0) throw new MagratheaApiException("Invalid 'skip'", 400);

		$whereSql = count($where) ? " WHERE ".implode(" AND ", $where) : "";
		$db = Database::Instance();
		$total = (int)$db->QueryOne("SELECT COUNT(*) FROM `logs`".$whereSql);
		$rows = $db->QueryAll(
			"SELECT `id`, `user_id`, `data`, `timestamp` FROM `logs`".$whereSql
			." ORDER BY `timestamp` DESC, `id` DESC LIMIT ".$limit." OFFSET ".$skip
		);
		$logs = array_map(fn($r) => self::FormatRow((array)$r), $rows);
		return [
			"total" => $total,
			"count" => count($logs),
			"logs" => $logs,
		];
	}

	/** Per-service summary: [{userId, count, lastLog}], most recently active first.
	 * (snake_case alias: Database lower-cases column names.) */
	public static function Services(): array {
		$rows = Database::Instance()->QueryAll(
			"SELECT `user_id`, COUNT(*) AS `count`, MAX(`timestamp`) AS `last_log`"
			." FROM `logs` GROUP BY `user_id` ORDER BY `last_log` DESC"
		);
		return array_map(function($r) {
			$r = (array)$r;
			return [
				"userId" => $r["user_id"],
				"count" => (int)$r["count"],
				"lastLog" => self::IsoDate($r["last_log"]),
			];
		}, $rows);
	}

	public static function FormatRow(array $row): array {
		return [
			"id" => $row["id"],
			"userId" => $row["user_id"],
			"data" => json_decode($row["data"] ?? "null", true),
			"timestamp" => self::IsoDate($row["timestamp"]),
		];
	}

	/** `2026-10-05 12:00:00.123` (UTC, from DB) → `2026-10-05T12:00:00.123Z` */
	public static function IsoDate(?string $sqlDate): ?string {
		if ($sqlDate === null) return null;
		$date = new \DateTimeImmutable($sqlDate, new \DateTimeZone("UTC"));
		return $date->format("Y-m-d\TH:i:s.v\Z");
	}
}
