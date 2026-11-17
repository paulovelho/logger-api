<?php
namespace logger\Tests;

use logger\Log\LogControl;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaApiException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LogControl::Purge() / PurgeCount(), called directly (the /admin/* routes need the admin credential).
 * The purged rows belong to throwaway services named after the run (`<run>`, `<run>.<name>`), so no
 * real service's rows are touched; each test tells its audit rows apart by `performedBy`.
 */
class PurgeTest extends LoggerTestCase {

	private const DAYS = 365;

	public static function tearDownAfterClass(): void {
		Database::Instance()->Query(
			"DELETE FROM `".LogControl::LOGS."` WHERE `service` = '".LogControl::SELF_SERVICE."'"
			." AND JSON_VALUE(`data`, '$.targetService') LIKE '".self::$run."%'"
		);
		parent::tearDownAfterClass();
	}

	public static function Tables(): array {
		return [
			"logs" => [ LogControl::LOGS, "purge_logs" ],
			"errors" => [ LogControl::ERRORS, "purge_errors" ],
		];
	}

	/**
	 * Stores `count` rows tagged `tag`, received at `when` (e.g. "-400 days") and occurred at `occurred`
	 * (default: `when`), for `service` (default: the run's throwaway service).
	 */
	private static function Seed(string $table, string $tag, int $count, string $when, ?string $occurred = null, ?string $service = null): void {
		LogControl::InsertRows($table, array_fill(0, $count, [
			"service" => $service ?? self::$run, "environment" => "test", "data" => json_encode(self::Data($tag)),
			"timestamp" => self::Sql($when), "occurred_at" => self::Sql($occurred ?? $when),
		]));
	}

	/** A throwaway service of the test's own, so its leftovers can't match another test's purge. */
	private static function Target(string $name): string {
		return self::$run.".".$name;
	}

	/** An `occurredBefore` a year ago. */
	private static function Cutoff(): \DateTimeImmutable {
		return new \DateTimeImmutable("-".self::DAYS." days", new \DateTimeZone("UTC"));
	}

	/** The cutoff as the API returns it (UTC, ms). */
	private static function Iso(\DateTimeImmutable $date): string {
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.v\Z");
	}

	/** Data of the audit rows written by `performedBy` for `target` (default: the run's service), oldest first. */
	private static function Audits(string $performedBy, ?string $target = null): array {
		$rows = Database::Instance()->QueryAll(
			"SELECT `data` FROM `".LogControl::LOGS."` WHERE `service` = '".LogControl::SELF_SERVICE."'"
			." AND JSON_VALUE(`data`, '$.targetService') = '".($target ?? self::$run)."'"
			." AND JSON_VALUE(`data`, '$.performedBy') = '".addslashes($performedBy)."' ORDER BY `id`"
		);
		return array_map(fn($r) => json_decode(((array)$r)["data"], true), $rows);
	}

	private function assertRejected(int $status, callable $call): void {
		try {
			$call();
			$this->fail("expected a ".$status);
		} catch (MagratheaApiException $ex) {
			$this->assertSame($status, $ex->getCode());
		}
	}

	// ── days mode (olderThanDays, on `timestamp`) ────────────────────────────────

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

	public function testMaxIdNeedsOccurredBefore(): void {
		$this->assertRejected(400, fn() => LogControl::Purge(LogControl::LOGS, self::Target("days-maxid"), self::DAYS, "phpunit-days-maxid", 1));
	}

	// ── date mode (occurredBefore, on `occurred_at`) ─────────────────────────────

	#[DataProvider("Tables")]
	public function testOccurredBeforeDeletesByEventTime(string $table, string $action): void {
		$target = self::Target("event-".$action);
		self::Seed($table, "old-event-".$action, 2, "-1 day", occurred: "-400 days", service: $target);
		self::Seed($table, "old-arrival-".$action, 3, "-400 days", occurred: "-1 day", service: $target);

		$cutoff = self::Cutoff();
		$by = "phpunit-event-".$action;
		$this->assertSame([ "deleted" => 2 ], LogControl::Purge($table, $target, $cutoff, $by));
		$this->assertCount(0, self::Rows($table, "old-event-".$action));
		$this->assertCount(3, self::Rows($table, "old-arrival-".$action));
		$this->assertSame([ [
			"action" => $action,
			"targetService" => $target,
			"occurredBefore" => self::Iso($cutoff),
			"maxId" => null,
			"deletedCount" => 2,
			"performedBy" => $by,
		] ], self::Audits($by, $target));
	}

	public function testPreviewCountsWhatTheDeleteRemoves(): void {
		$target = self::Target("preview");
		self::Seed(LogControl::LOGS, "preview-old", 4, "-400 days", service: $target);
		self::Seed(LogControl::LOGS, "preview-recent", 1, "-1 day", service: $target);
		$ids = array_map(fn($r) => (int)$r["id"], self::Rows(LogControl::LOGS, "preview-old"));

		$cutoff = self::Cutoff();
		$preview = LogControl::PurgeCount(LogControl::LOGS, $target, $cutoff);
		$this->assertSame([ "count" => 4, "occurredBefore" => self::Iso($cutoff), "maxId" => max($ids) ], $preview);

		$by = "phpunit-preview";
		$this->assertSame([ "deleted" => 4 ], LogControl::Purge(LogControl::LOGS, $target, $cutoff, $by, $preview["maxId"]));
		$this->assertCount(1, self::Rows(LogControl::LOGS, "preview-recent"));
		$this->assertSame(max($ids), self::Audits($by, $target)[0]["maxId"] ?? null);
	}

	// a late-synced offline batch: old event time, stored between the preview and the confirmation
	public function testMaxIdSparesRowsStoredAfterThePreview(): void {
		$target = self::Target("maxid");
		self::Seed(LogControl::LOGS, "before-preview", 2, "-1 day", occurred: "-400 days", service: $target);
		$cutoff = self::Cutoff();
		$preview = LogControl::PurgeCount(LogControl::LOGS, $target, $cutoff);
		self::Seed(LogControl::LOGS, "after-preview", 3, "now", occurred: "-400 days", service: $target);

		$this->assertSame([ "deleted" => 2 ], LogControl::Purge(LogControl::LOGS, $target, $cutoff, "phpunit-maxid", $preview["maxId"]));
		$this->assertCount(0, self::Rows(LogControl::LOGS, "before-preview"));
		$this->assertCount(3, self::Rows(LogControl::LOGS, "after-preview"));
	}

	public function testNothingToPreview(): void {
		$cutoff = self::Cutoff();
		$this->assertSame(
			[ "count" => 0, "occurredBefore" => self::Iso($cutoff), "maxId" => null ],
			LogControl::PurgeCount(LogControl::ERRORS, self::Target("empty"), $cutoff)
		);
	}

	// `occurred_at` is never later than its arrival, so a future cutoff would take entries arriving now
	public function testFutureOccurredBeforeIsRejected(): void {
		$target = self::Target("future");
		self::Seed(LogControl::LOGS, "future", 1, "-1 day", service: $target);
		$future = new \DateTimeImmutable("+1 hour", new \DateTimeZone("UTC"));

		$this->assertRejected(400, fn() => LogControl::PurgeCount(LogControl::LOGS, $target, $future));
		$this->assertRejected(400, fn() => LogControl::Purge(LogControl::LOGS, $target, $future, "phpunit-future"));
		$this->assertCount(1, self::Rows(LogControl::LOGS, "future"));
		$this->assertSame([], self::Audits("phpunit-future", $target));
	}
}
