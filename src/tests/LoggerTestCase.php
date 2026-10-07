<?php
namespace logger\Tests;

use logger\Log\LogControl;
use Magrathea2\DB\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration helpers: HTTP calls against the running instance (LOGGER_TEST_URL, default
 * http://localhost inside the container) and DB checks. Every row a test writes carries this
 * run's `testRun` marker in its data, and is deleted again after the class.
 * The service is LOGGER_TEST_SERVICE, or the first active, non-readonly one in config.json.
 */
abstract class LoggerTestCase extends TestCase {

	protected static string $run;
	private static ?string $token = null;

	public static function setUpBeforeClass(): void {
		self::$run = "phpunit-".bin2hex(random_bytes(6));
	}

	public static function tearDownAfterClass(): void {
		foreach ([ LogControl::LOGS, LogControl::ERRORS ] as $table) {
			Database::Instance()->Query(
				"DELETE FROM `".$table."` WHERE JSON_VALUE(`data`, '$.testRun') = '".self::$run."'"
			);
		}
	}

	protected static function Url(): string {
		return rtrim(getenv("LOGGER_TEST_URL") ?: "http://localhost", "/");
	}

	/** @return array{0:string, 1:string} service, secret */
	private static function Credential(): array {
		$users = json_decode((string)file_get_contents(__DIR__."/../../config.json"), true)["users"] ?? [];
		$wanted = getenv("LOGGER_TEST_SERVICE") ?: null;
		foreach ($users as $user) {
			$id = $user["service"] ?? $user["userId"] ?? null;
			if ($wanted !== null ? $id === $wanted : (empty($user["readonly"]) && ($user["active"] ?? true) !== false)) {
				return [ $id, $user["secret"] ];
			}
		}
		self::fail("No usable service in config.json (set LOGGER_TEST_SERVICE)");
	}

	protected static function Service(): string {
		return self::Credential()[0];
	}

	protected static function Token(): string {
		if (self::$token === null) {
			[ $service, $secret ] = self::Credential();
			[ $status, $json ] = self::Http("POST", "/login", [ "service" => $service, "secret" => $secret ], null);
			if ($status !== 200) self::fail("Login failed: ".json_encode($json));
			self::$token = $json["data"]["token"];
		}
		return self::$token;
	}

	/**
	 * @param mixed       $body  encoded as JSON unless it's already a string
	 * @param string|null $token defaults to the test service's; null for none
	 * @return array{0:int, 1:?array} HTTP status, decoded JSON
	 */
	protected static function Http(string $method, string $path, $body = null, ?string $token = "default"): array {
		$headers = [ "Content-Type: application/json" ];
		if ($token === "default") $token = self::Token();
		if ($token !== null) $headers[] = "Authorization: Bearer ".$token;
		$ch = curl_init(self::Url().$path);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 10,
		]);
		if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
		$raw = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [ $status, json_decode((string)$raw, true) ];
	}

	/** Rows of this run in `table` (optionally only those whose data has `tag`), oldest first. */
	protected static function Rows(string $table, ?string $tag = null): array {
		$sql = "SELECT `id`, `service`, `environment`, `data`, `timestamp`, `occurred_at` FROM `".$table."`"
			." WHERE JSON_VALUE(`data`, '$.testRun') = '".self::$run."'";
		if ($tag !== null) $sql .= " AND JSON_VALUE(`data`, '$.tag') = '".addslashes($tag)."'";
		return array_map(fn($r) => (array)$r, Database::Instance()->QueryAll($sql." ORDER BY `id`"));
	}

	/** A data object tagged with this run, so it can be found and cleaned up. */
	protected static function Data(string $tag, array $extra = []): array {
		return [ "testRun" => self::$run, "tag" => $tag ] + $extra;
	}
}
