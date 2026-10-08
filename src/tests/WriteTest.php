<?php
namespace logger\Tests;

use logger\Log\LogControl;

/** POST /log and /error: existing clients (no new keys) behave as in 1.2; the new reserved keys. */
class WriteTest extends LoggerTestCase {

	private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

	private static function Iso(string $sql): string {
		return LogControl::IsoDate($sql);
	}

	public function testExistingClientRegression(): void {
		$body = self::Data("regression", [ "environment" => "production", "event" => "page_view", "nested" => [ "a" => 1 ], "emptyObj" => new \stdClass(), "emptyList" => [] ]);
		[ $status, $json ] = self::Http("POST", "/log", $body);

		$this->assertSame(200, $status);
		$this->assertTrue($json["success"]);
		$this->assertSame([ "id", "timestamp" ], array_keys($json["data"]));
		$this->assertIsInt($json["data"]["id"]);
		$this->assertMatchesRegularExpression(self::ISO, $json["data"]["timestamp"]);

		$rows = self::Rows(LogControl::LOGS, "regression");
		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame($json["data"]["id"], (int)$row["id"]);
		$this->assertSame(self::Service(), $row["service"]);
		$this->assertSame("production", $row["environment"]);
		$stored = json_decode($row["data"]);
		$this->assertEquals((object)[ "testRun" => self::$run, "tag" => "regression", "event" => "page_view", "nested" => (object)[ "a" => 1 ], "emptyObj" => new \stdClass(), "emptyList" => [] ], $stored);
		$this->assertSame("{}", json_encode($stored->emptyObj));
		$this->assertSame($json["data"]["timestamp"], self::Iso($row["timestamp"]));
		$this->assertSame($row["timestamp"], $row["occurred_at"]);

		[ $status, $report ] = self::Http("GET", "/report?limit=1000");
		$this->assertSame(200, $status);
		$entry = current(array_filter($report["data"]["logs"], fn($l) => $l["_id"] === $json["data"]["id"]));
		$this->assertSame([ "_id", "service", "serviceName", "environment", "data", "timestamp", "occurredAt" ], array_keys($entry));
		$this->assertSame($entry["timestamp"], $entry["occurredAt"]);
	}

	public function testDefaultsAndEmptyBody(): void {
		[ $status, $json ] = self::Http("POST", "/error", self::Data("defaults"));
		$this->assertSame(200, $status);
		$row = self::Rows(LogControl::ERRORS, "defaults")[0];
		$this->assertSame("unknown", $row["environment"]);
		$this->assertSame($row["timestamp"], $row["occurred_at"]);

		// an empty body is stored as {} (not tagged, so remove it by id)
		[ $status, $json ] = self::Http("POST", "/log", "");
		$this->assertSame(200, $status);
		$db = \Magrathea2\DB\Database::Instance();
		$this->assertSame("{}", $db->QueryOne("SELECT `data` FROM `logger_logs` WHERE `id` = ".(int)$json["data"]["id"]));
		$db->Query("DELETE FROM `logger_logs` WHERE `id` = ".(int)$json["data"]["id"]);
	}

	public function testReservedKeysAreStrippedAndCorrected(): void {
		[ $status, $json ] = self::Http("POST", "/log", self::Data("skew", [
			"occurredAt" => "2026-01-01T09:00:00Z", "sentAt" => "2026-01-01T10:00:00Z",
		]));
		$this->assertSame(200, $status);
		$row = self::Rows(LogControl::LOGS, "skew")[0];
		$data = json_decode($row["data"], true);
		$this->assertArrayNotHasKey("occurredAt", $data);
		$this->assertArrayNotHasKey("sentAt", $data);
		$this->assertSame(self::Sql(self::Stored($row["timestamp"])->modify("-1 hour")->format(DATE_RFC3339_EXTENDED)), $row["occurred_at"]);
	}

	public function testOccurredAtOnlyIsKept(): void {
		self::Http("POST", "/error", self::Data("given", [ "occurredAt" => "2026-01-01T09:00:00.250-03:00" ]));
		$this->assertSame(self::Sql("2026-01-01T12:00:00.250Z"), self::Rows(LogControl::ERRORS, "given")[0]["occurred_at"]);
	}

	public static function BadDates(): array {
		return [
			"occurredAt number" => [ [ "occurredAt" => 1759655560 ], "Invalid 'occurredAt'" ],
			"occurredAt null" => [ [ "occurredAt" => null ], "Invalid 'occurredAt'" ],
			"occurredAt text" => [ [ "occurredAt" => "yesterday" ], "Invalid 'occurredAt'" ],
			"sentAt invalid" => [ [ "sentAt" => "2026-02-30T00:00:00Z" ], "Invalid 'sentAt'" ],
			"out of DATETIME range" => [ [ "occurredAt" => "1000-01-01T00:00:00Z", "sentAt" => "9999-01-01T00:00:00Z" ], "'occurredAt' is out of range" ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("BadDates")]
	public function testInvalidDatesGive400(array $extra, string $message): void {
		[ $status, $json ] = self::Http("POST", "/log", self::Data("bad-date", $extra));
		$this->assertSame(400, $status);
		$this->assertStringStartsWith($message, $json["data"]["message"]);
		$this->assertCount(0, self::Rows(LogControl::LOGS, "bad-date"));
	}

	public function testFromToFilterOnOccurredAt(): void {
		self::Http("POST", "/log", self::Data("old-event", [ "occurredAt" => "2001-02-03T04:05:06Z" ]));
		$ids = fn(string $query) => array_column(array_filter(
			self::Http("GET", "/report?limit=1000&".$query)[1]["data"]["logs"],
			fn($l) => ($l["data"]["tag"] ?? null) === "old-event" && ($l["data"]["testRun"] ?? null) === self::$run
		), "occurredAt");

		$this->assertSame([ "2001-02-03T04:05:06.000Z" ], $ids("from=2001-02-03&to=2001-02-04"));
		$this->assertSame([], $ids("from=".gmdate("Y-m-d")));
	}
}
