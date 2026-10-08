<?php
namespace logger\Tests;

use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use PHPUnit\Framework\Attributes\DataProvider;

/** POST /log/batch and /error/batch: validation (nothing stored on 4xx), all-or-nothing inserts. */
class BatchTest extends LoggerTestCase {

	private const SENT = "2026-10-07T14:03:11Z";

	private static function Entries(string $tag, int $count): array {
		return array_map(fn($i) => self::Data($tag, [ "i" => $i ]), range(0, $count - 1));
	}

	public function testStoresAllEntries(): void {
		[ $status, $json ] = self::Http("POST", "/log/batch", [
			"environment" => "production",
			"sentAt" => self::SENT,
			"entries" => [
				self::Data("ok", [ "occurredAt" => "2026-10-05T09:12:40Z", "event" => "game_start", "mode" => "classic" ]),
				self::Data("ok", [ "occurredAt" => "2026-10-05T09:31:02Z", "event" => "game_over", "score" => 18432 ]),
				self::Data("ok", [ "event" => "no_time", "empty" => new \stdClass() ]),
			],
		]);
		$this->assertSame(200, $status);
		$this->assertSame([ "success" => true, "data" => [ "count" => 3 ] ], $json);

		$rows = self::Rows(LogControl::LOGS, "ok");
		$this->assertCount(3, $rows);
		$received = $rows[0]["timestamp"];
		foreach ($rows as $row) {
			$this->assertSame($received, $row["timestamp"], "one receivedAt per batch");
			$this->assertSame("production", $row["environment"]);
			$this->assertArrayNotHasKey("occurredAt", json_decode($row["data"], true));
		}
		$at = fn(string $age) => self::Sql(self::Stored($received)->modify($age)->format(DATE_RFC3339_EXTENDED));
		// sentAt - occurredAt = 2d 4:50:31 and 2d 4:32:09
		$this->assertSame($at("-2 days -4 hours -50 minutes -31 seconds"), $rows[0]["occurred_at"]);
		$this->assertSame($at("-2 days -4 hours -32 minutes -9 seconds"), $rows[1]["occurred_at"]);
		$this->assertSame($received, $rows[2]["occurred_at"]);
		$this->assertSame("{}", json_encode(json_decode($rows[2]["data"])->empty));
	}

	public function testErrorBatchWritesErrors(): void {
		[ $status, $json ] = self::Http("POST", "/error/batch", [ "sentAt" => self::SENT, "entries" => self::Entries("errors", 2) ]);
		$this->assertSame(200, $status);
		$this->assertSame(2, $json["data"]["count"]);
		$this->assertCount(2, self::Rows(LogControl::ERRORS, "errors"));
		$this->assertCount(0, self::Rows(LogControl::LOGS, "errors"));
		$this->assertSame("unknown", self::Rows(LogControl::ERRORS, "errors")[0]["environment"]);
	}

	public function testHundredEntriesIsTheLimit(): void {
		[ $status ] = self::Http("POST", "/log/batch", [ "sentAt" => self::SENT, "entries" => self::Entries("hundred", 100) ]);
		$this->assertSame(200, $status);
		$this->assertCount(100, self::Rows(LogControl::LOGS, "hundred"));
	}

	public static function InvalidBatches(): array {
		$ok = fn() => self::Data("rejected");
		return [
			"body is an array" => [ fn() => [ $ok() ], "Body must be a JSON object" ],
			"body is a scalar" => [ fn() => "42", "Body must be a JSON object" ],
			"entries missing" => [ fn() => [ "sentAt" => self::SENT ], "'entries' must be a non-empty array" ],
			"entries is an object" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ "a" => $ok() ] ], "'entries' must be a non-empty array" ],
			"entries is a string" => [ fn() => [ "sentAt" => self::SENT, "entries" => "x" ], "'entries' must be a non-empty array" ],
			"entries is empty" => [ fn() => [ "sentAt" => self::SENT, "entries" => [] ], "'entries' must be a non-empty array" ],
			"101 entries" => [ fn() => [ "sentAt" => self::SENT, "entries" => self::Entries("rejected", 101) ], "'entries' can't have more than 100 items" ],
			"sentAt missing" => [ fn() => [ "entries" => [ $ok() ] ], "'sentAt' is required" ],
			"sentAt invalid" => [ fn() => [ "sentAt" => "tomorrow", "entries" => [ $ok() ] ], "Invalid 'sentAt'" ],
			"sentAt null" => [ fn() => [ "sentAt" => null, "entries" => [ $ok() ] ], "Invalid 'sentAt'" ],
			"environment invalid" => [ fn() => [ "sentAt" => self::SENT, "environment" => [ "x" ], "entries" => [ $ok() ] ], "Invalid 'environment'" ],
			"environment too long" => [ fn() => [ "sentAt" => self::SENT, "environment" => str_repeat("e", 51), "entries" => [ $ok() ] ], "Invalid 'environment'" ],
			"entry is an array" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok(), $ok(), $ok(), [ 1, 2 ] ] ], "entries[3]: must be an object" ],
			"entry is a scalar" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok(), "x" ] ], "entries[1]: must be an object" ],
			"entry is null" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ null ] ], "entries[0]: must be an object" ],
			"entry has environment" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok(), $ok() + [ "environment" => "x" ] ] ], "entries[1]: 'environment' is batch-level only" ],
			"entry has sentAt" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok() + [ "sentAt" => self::SENT ] ] ], "entries[0]: 'sentAt' is batch-level only" ],
			"entry occurredAt invalid" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok(), $ok(), $ok() + [ "occurredAt" => 123 ] ] ], "entries[2]: Invalid 'occurredAt'" ],
			"entry occurredAt out of range" => [ fn() => [ "sentAt" => "9999-01-01T00:00:00Z", "entries" => [ $ok(), $ok() + [ "occurredAt" => "1000-01-01T00:00:00Z" ] ] ], "entries[1]: 'occurredAt' is out of range" ],
			"first offending index wins" => [ fn() => [ "sentAt" => self::SENT, "entries" => [ $ok(), 5, null ] ], "entries[1]: must be an object" ],
		];
	}

	#[DataProvider("InvalidBatches")]
	public function testInvalidBatchGives400AndStoresNothing(\Closure $body, string $message): void {
		foreach ([ "/log/batch" => LogControl::LOGS, "/error/batch" => LogControl::ERRORS ] as $path => $table) {
			[ $status, $json ] = self::Http("POST", $path, $body());
			$this->assertSame(400, $status, $path);
			$this->assertFalse($json["success"]);
			$this->assertStringStartsWith($message, $json["data"]["message"], $path);
			$this->assertCount(0, self::Rows($table, "rejected"), $path." stored rows");
		}
	}

	public function testBodyOver256KbGives413(): void {
		$entry = self::Data("too-big", [ "blob" => str_repeat("a", LogControl::MAX_BATCH_BYTES) ]);
		[ $status, $json ] = self::Http("POST", "/log/batch", [ "sentAt" => self::SENT, "entries" => [ $entry ] ]);
		$this->assertSame(413, $status);
		$this->assertFalse($json["success"]);
		$this->assertSame(413, $json["data"]["code"]);
		$this->assertCount(0, self::Rows(LogControl::LOGS, "too-big"));
	}

	public function testBodyJustUnder256KbIsAccepted(): void {
		$body = [ "sentAt" => self::SENT, "entries" => [ self::Data("big", [ "blob" => "" ]) ] ];
		$body["entries"][0]["blob"] = str_repeat("a", LogControl::MAX_BATCH_BYTES - strlen(json_encode($body)));
		$this->assertSame(LogControl::MAX_BATCH_BYTES, strlen(json_encode($body)));
		[ $status ] = self::Http("POST", "/log/batch", $body);
		$this->assertSame(200, $status);
		$this->assertCount(1, self::Rows(LogControl::LOGS, "big"));
	}

	public function testNeedsToken(): void {
		[ $status ] = self::Http("POST", "/log/batch", [ "sentAt" => self::SENT, "entries" => [ self::Data("no-auth") ] ], null);
		$this->assertSame(401, $status);
		[ $status ] = self::Http("POST", "/error/batch", [ "sentAt" => self::SENT, "entries" => [ self::Data("no-auth") ] ], "not-a-token");
		$this->assertSame(401, $status);
		$this->assertCount(0, self::Rows(LogControl::LOGS, "no-auth"));
	}

	/** A row the DB rejects (invalid JSON fails the column's json_valid CHECK) rolls back the whole insert. */
	public function testInsertFailureRollsBackEveryRow(): void {
		$row = fn(string $data) => [
			"service" => self::Service(), "environment" => "test", "data" => $data,
			"timestamp" => "2026-10-07 14:00:00.000", "occurred_at" => "2026-10-07 14:00:00.000",
		];
		$good = fn() => $row(json_encode(self::Data("rollback")));

		try {
			LogControl::InsertRows(LogControl::LOGS, [ $good(), $good(), $row("{not json"), $good() ]);
			$this->fail("the insert should have failed");
		} catch (MagratheaApiException $ex) {
			$this->assertSame(500, $ex->getCode());
		} catch (\Throwable $ex) {
			// a mysqli/Magrathea DB exception: LoggerApi answers it with a generic 500
		}
		$this->assertCount(0, self::Rows(LogControl::LOGS, "rollback"));

		// control: the same rows without the bad one are stored
		LogControl::InsertRows(LogControl::LOGS, [ $good(), $good() ]);
		$this->assertCount(2, self::Rows(LogControl::LOGS, "rollback"));
	}
}
