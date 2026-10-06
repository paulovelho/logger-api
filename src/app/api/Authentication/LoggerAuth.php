<?php
namespace logger;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiAuth;

/**
 * Service authentication. Tokens are HS256 `{service, readonly, iat}` with no `exp`, exactly what
 * Node 1.1.x issued, so the tokens crawler, api, auth and profiles already hold keep working.
 * Mongo-era `{userId, iat}` tokens are still accepted.
 *
 * Route checks (all re-read config.json, so editing it applies immediately):
 *   IsService  valid token for a service that still exists (removing it revokes its tokens)
 *   IsActive   + not `"active": false`   (POST /log, /error)
 *   IsAdmin    + `"readonly": true`      (/admin/*)
 */
class LoggerAuth extends MagratheaApiAuth {

	const MIN_KEY_BYTES = 32;

	/** Set by IsService() for the downstream controls. */
	public ?string $serviceId = null;

	public function GetSecret(): string {
		$key = (string)parent::GetSecret();
		// firebase/php-jwt v7 rejects HS256 keys under 32 bytes; say so instead of a vague 401
		if (strlen($key) < self::MIN_KEY_BYTES) {
			throw new MagratheaApiException("jwt_key (magrathea.conf) must be at least ".self::MIN_KEY_BYTES." bytes", 500);
		}
		return $key;
	}

	// The vendor versions run the secret through strtr('-_', '+/'), which breaks Node-issued
	// tokens whenever the secret contains '-' or '_'. Use the raw secret, like jsonwebtoken did.
	public function jwtEncode($payload) {
		return JWT::encode($payload, $this->GetSecret(), $this->jwtEncodeType);
	}

	private function Verify(string $token): object {
		return JWT::decode($token, new Key($this->GetSecret(), $this->jwtEncodeType));
	}

	// Same workaround as api's AuthApi::jwtDecode(): php-jwt's decode failures aren't
	// MagratheaApiExceptions, so they'd otherwise come back as HTTP 200 with success:false.
	public function jwtDecode($token) {
		try {
			return $this->Verify($token);
		} catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $ex) {
			throw new MagratheaApiException("Invalid token", 401);
		}
	}

	// POST /login — {service, secret}; `userId` is accepted in place of `service`
	public function Login() {
		$post = $this->GetPost();
		$service = $post["service"] ?? $post["userId"] ?? null;
		$secret = $post["secret"] ?? null;
		if (!is_string($service) || !is_string($secret) || !ServiceUsers::Validate($service, $secret)) {
			throw new MagratheaApiException("Invalid credentials", 401);
		}
		return [ "token" => $this->jwtEncode([
			"service" => $service,
			"readonly" => ServiceUsers::IsReadonly($service),
			"iat" => time(),
		]) ];
	}

	// POST /token — decodes a token signed with this instance's key
	public function Token() {
		$token = $this->GetPost()["token"] ?? null;
		if (!is_string($token) || $token === "") throw new MagratheaApiException("token is required", 400);
		try {
			return [ "decoded" => $this->Verify($token) ];
		} catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $ex) {
			throw new MagratheaApiException($ex->getMessage(), 400);
		}
	}

	/** Base authorization: see the class comment. */
	public function IsService($params = []): bool {
		$token = $this->getTokenByType("Bearer");
		if (!$token) throw new MagratheaApiException("Missing or invalid Authorization header", 401);
		$payload = $this->jwtDecode($token);
		$service = $payload->service ?? $payload->userId ?? null;
		if (!is_string($service) || !ServiceUsers::Exists($service)) {
			throw new MagratheaApiException("Invalid token", 401);
		}
		$this->userInfo = $payload;
		$this->serviceId = $service;
		return true;
	}

	public function IsActive($params = []): bool {
		$this->IsService($params);
		if (!ServiceUsers::IsActive($this->serviceId)) throw new MagratheaApiException("Forbidden", 403);
		return true;
	}

	// `readonly` is read from config.json, not the token claim (Node's claim went stale on config edits)
	public function IsAdmin($params = []): bool {
		$this->IsService($params);
		if (!ServiceUsers::IsReadonly($this->serviceId)) throw new MagratheaApiException("Forbidden", 403);
		return true;
	}
}
