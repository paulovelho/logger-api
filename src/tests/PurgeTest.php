<?php
namespace logger\Tests;

use logger\Log\LogControl;
use Magrathea\DB\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LogControl::Purge(), called directly (DELETE /admin/* needs the admin credential). The purged rows
 * belong to a throwaway service named after the run, so no real service's rows are touched; each
 * test tells its audit rows apart by `performedBy`.
 */
class PurgeTest extends LoggerTestCase {

	private const DAYS = 365;

	public static function tearDownAfterClass(): void {
		Database::Instance()->Query(
			"DELETE FROM `".LogControl::LOGS."` WHERE `service` = '".LogControl::SELF_SERVICE."'"
			." AND JSON_VALUE(`data`, '$.targetService') = '".self::$run."'"
		);
		parent::tearDownAfterClass();
	}

	public static function Tables(): array {
		return [
			"logs" => [ LogControl::LOGS, "purge_logs" ],
			"errors" => [ LogControl::ERRORS, "purge_errors" ],
		];
	}

	/** Stores `count` rows tagged `tag` for the throwaway service, received at `when` (e.g. "-400 days"). */
	private static function Seed(string $table, string $tag, int $count, string $when): void {
		$at = self::Sql($when);
		LogControl::InsertRows($table, array_fill(0, $count, [
			"service" => self::$run, "environment" => "test", "data" => json_encode(self::Data($tag)),
			"timestamp" => $at, "occurred_at" => $at,
		]));
	}

	/** Data of the audit rows written by `performedBy` for this run's service, oldest first. */
	private static function Audits(string $performedBy): array {
		$rows = Database::Instance()->QueryAll(
			"SELECT `data` FROM `".LogControl::LOGS."` WHERE `service` = '".LogControl::SELF_SERVICE."'"
			." AND JSON_VALUE(`data`, '$.targetService') = '".self::$run."'"
			." AND JSON_VALUE(`data`, '$.performedBy') = '".addslashes($performedBy)."' ORDER BY `id`"
		);
		return array_map(fn($r) => json_decode(((array)$r)["data"], true), $rows);
	}

	#[DataProvider("Tables")]
	public function testDeletesOnlyOldRowsAndWritesAnAudit(string $table, string $action): void {
		self::Seed($table, "old-".$action, 3, "-400 days");
		self::Seed($table, "recent-".$action, 2, "-10 days");

		$by = "phpunit-".$action;
		$this->assertSame([ "deleted" => 3 ], LogControl::Purge($table, self::$run, self::DAYS, $by));
		$this->assertCount(0, self::Rows($table, "old-".$action));
		$this->assertCount(2, self::Rows($table, "recent-".$action));
		$this->assertSame([ [
			"action" => $action,
			"targetService" => self::$run,
			"cutoffDays" => self::DAYS,
			"deletedCount" => 3,
			"performedBy" => $by,
		] ], self::Audits($by));
	}

	// the count is the DELETE's own, not the rows affected by the insert before it
	public function testNothingToDeleteCountsZero(): void {
		self::Seed(LogControl::LOGS, "only-recent", 2, "-1 day");

		$by = "phpunit-zero";
		$this->assertSame([ "deleted" => 0 ], LogControl::Purge(LogControl::LOGS, self::$run, self::DAYS, $by));
		$this->assertCount(2, self::Rows(LogControl::LOGS, "only-recent"));
		$this->assertSame(0, self::Audits($by)[0]["deletedCount"] ?? null);
	}
}
