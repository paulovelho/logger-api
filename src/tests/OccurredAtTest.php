<?php
namespace logger\Tests;

use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The clock-skew rules (LogControl::OccurredAt) and client date parsing. No DB or HTTP. */
class OccurredAtTest extends TestCase {

	private const RECEIVED = "2026-10-07T14:00:00.000Z";

	private static function D(?string $iso): ?\DateTimeImmutable {
		return $iso === null ? null : LogControl::ClientDate($iso, "test");
	}

	private static function Iso(\DateTimeImmutable $date): string {
		return $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.v\Z");
	}

	/** [occurredAt, sentAt, expected occurred_at], with receivedAt = RECEIVED */
	public static function SkewTable(): array {
		return [
			"neither: arrival time" => [ null, null, self::RECEIVED ],
			"occurredAt only: kept as given" => [ "2026-10-05T09:12:40.250Z", null, "2026-10-05T09:12:40.250Z" ],
			"both: age kept, client clock ignored" => [ "2026-10-05T09:00:00Z", "2026-10-05T10:00:00Z", "2026-10-07T13:00:00.000Z" ],
			"both, client clock a year behind" => [ "2025-10-07T13:59:00Z", "2025-10-07T14:00:00Z", "2026-10-07T13:59:00.000Z" ],
			"both, with offsets" => [ "2026-10-05T06:00:00.500-03:00", "2026-10-05T11:00:01+02:00", "2026-10-07T13:59:59.500Z" ],
			"sentAt only: arrival time" => [ null, "2026-10-01T00:00:00Z", self::RECEIVED ],
			"negative age clamped to 0" => [ "2026-10-05T10:00:00Z", "2026-10-05T09:00:00Z", self::RECEIVED ],
			"future occurredAt clamped to arrival" => [ "2026-10-08T00:00:00Z", null, self::RECEIVED ],
			"future occurredAt with sentAt: age still applies" => [ "2027-01-01T00:00:00Z", "2027-01-01T00:00:30Z", "2026-10-07T13:59:30.000Z" ],
			"same instant: arrival time" => [ "2026-10-05T09:00:00Z", "2026-10-05T09:00:00Z", self::RECEIVED ],
		];
	}

	#[DataProvider("SkewTable")]
	public function testSkewTable(?string $occurredAt, ?string $sentAt, string $expected): void {
		$received = self::D(self::RECEIVED);
		$result = LogControl::OccurredAt($received, self::D($occurredAt), self::D($sentAt));
		$this->assertSame($expected, self::Iso($result));
		$this->assertLessThanOrEqual($received, $result);
	}

	public static function ValidDates(): array {
		return [
			"no offset is UTC" => [ "2026-10-05T09:12:40", "2026-10-05T09:12:40.000Z" ],
			"Z" => [ "2026-10-05T09:12:40Z", "2026-10-05T09:12:40.000Z" ],
			"offset" => [ "2026-10-05T09:12:40-03:00", "2026-10-05T12:12:40.000Z" ],
			"compact offset" => [ "2026-10-05T09:12:40+0130", "2026-10-05T07:42:40.000Z" ],
			"milliseconds" => [ "2026-10-05T09:12:40.123Z", "2026-10-05T09:12:40.123Z" ],
			"microseconds" => [ "2026-10-05T09:12:40.123456Z", "2026-10-05T09:12:40.123Z" ],
			"no seconds" => [ "2026-10-05T09:12Z", "2026-10-05T09:12:00.000Z" ],
			"space separator" => [ "2026-10-05 09:12:40", "2026-10-05T09:12:40.000Z" ],
			"date only" => [ "2026-10-05", "2026-10-05T00:00:00.000Z" ],
		];
	}

	#[DataProvider("ValidDates")]
	public function testClientDateParses(string $value, string $expected): void {
		$this->assertSame($expected, self::Iso(LogControl::ClientDate($value, "occurredAt")));
	}

	public static function InvalidDates(): array {
		return [
			"null" => [ null ], "number" => [ 1759655560000 ], "bool" => [ true ], "array" => [ [] ],
			"object" => [ new \stdClass() ], "empty" => [ "" ], "relative" => [ "now" ], "words" => [ "yesterday" ],
			"rolled-over day" => [ "2026-02-30T10:00:00Z" ], "hour 25" => [ "2026-10-07T25:00:00Z" ],
			"month 13" => [ "2026-13-01T00:00:00Z" ], "year 0999" => [ "0999-01-01T00:00:00Z" ],
			"garbage suffix" => [ "2026-10-07T10:00:00Zjunk" ], "US format" => [ "10/07/2026" ],
		];
	}

	#[DataProvider("InvalidDates")]
	public function testClientDateRejects($value): void {
		try {
			LogControl::ClientDate($value, "occurredAt", "entries[2]: ");
			$this->fail("accepted ".var_export($value, true));
		} catch (MagratheaApiException $ex) {
			$this->assertSame(400, $ex->getCode());
			$this->assertStringStartsWith("entries[2]: Invalid 'occurredAt'", $ex->getMessage());
		}
	}
}
