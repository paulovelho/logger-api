<?php

namespace logger;

use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaPHP;

/**
 * Services allowed to log, read from config.json at the module root (outside the docroot).
 * Static by design: services are added with configure.sh, never through the API.
 */
class ServiceUsers {

	private static ?array $users = null;

	private static function Load(): array {
		if (self::$users !== null) return self::$users;
		$path = MagratheaPHP::Instance()->GetAppRoot()."/../../config.json";
		$json = @file_get_contents($path);
		$config = $json === false ? null : json_decode($json, true);
		if (!is_array($config) || !is_array($config["users"] ?? null)) {
			throw new MagratheaApiException("config.json missing or invalid", 500);
		}
		self::$users = $config["users"];
		return self::$users;
	}

	// Entries were written as "userId" by configure.sh, but older hand-edited ones use "service".
	private static function IdOf(array $user): ?string {
		return $user["userId"] ?? $user["service"] ?? null;
	}

	private static function Find(string $userId): ?array {
		foreach (self::Load() as $user) {
			if (self::IdOf($user) === $userId) return $user;
		}
		return null;
	}

	public static function Exists(string $userId): bool {
		return self::Find($userId) !== null;
	}

	/** `"readonly": true` services can read their /report but not write. */
	public static function IsReadonly(string $userId): bool {
		return (self::Find($userId)["readonly"] ?? false) === true;
	}

	public static function Validate(string $userId, string $secret): bool {
		foreach (self::Load() as $user) {
			if (self::IdOf($user) === $userId && hash_equals((string)($user["secret"] ?? ""), $secret)) {
				return true;
			}
		}
		return false;
	}
}
