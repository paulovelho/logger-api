<?php

namespace logger;

use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaPHP;

/**
 * Services allowed to log, read from config.json at the module root (outside the docroot).
 * Static by design: services are added with configure.sh, never through the API.
 * Read on every request, so edits apply without a restart.
 *
 * Entry flags (as in Node 1.1.x):
 *   name      display name (falls back to the id)
 *   readonly  admin credential: may call /admin/* (it can still write)
 *   active    false → POST /log and /error get 403, and it's left out of /admin/services
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

	// Node wrote entries as "service"; configure.sh (Mongo era) wrote "userId". Both are accepted.
	private static function IdOf(array $user): ?string {
		$id = $user["service"] ?? $user["userId"] ?? null;
		return is_string($id) ? $id : null;
	}

	private static function Find(string $service): ?array {
		foreach (self::Load() as $user) {
			if (is_array($user) && self::IdOf($user) === $service) return $user;
		}
		return null;
	}

	public static function Exists(string $service): bool {
		return self::Find($service) !== null;
	}

	/** Exists and isn't `"active": false`. */
	public static function IsActive(string $service): bool {
		$user = self::Find($service);
		return $user !== null && ($user["active"] ?? true) !== false;
	}

	/** `"readonly": true` marks the admin credential. */
	public static function IsReadonly(string $service): bool {
		return !empty(self::Find($service)["readonly"] ?? false);
	}

	/** Display name; unknown services (e.g. the `logger` self-audit) get their id back. */
	public static function Name(string $service): string {
		$name = self::Find($service)["name"] ?? null;
		return is_string($name) && $name !== "" ? $name : $service;
	}

	/** @return array<array{service:string, name:string}> active services, in config order */
	public static function Active(): array {
		$active = [];
		foreach (self::Load() as $user) {
			if (!is_array($user)) continue;
			$id = self::IdOf($user);
			if ($id === null || ($user["active"] ?? true) === false) continue;
			$active[] = [ "service" => $id, "name" => self::Name($id) ];
		}
		return $active;
	}

	public static function Validate(string $service, string $secret): bool {
		$user = self::Find($service);
		return $user !== null && hash_equals((string)($user["secret"] ?? ""), $secret);
	}
}
