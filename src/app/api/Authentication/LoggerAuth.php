<?php
namespace logger;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiAuth;

/**
 * Service authentication. Tokens are HS256 `{userId, iat}` with no `exp`, the same format the
 * Node version issued, so tokens already held by crawler and api keep working.
 */
class LoggerAuth extends MagratheaApiAuth {

	const MIN_KEY_BYTES = 32;

	/** Set by IsService() for the downstream controls. */
	public ?string $serviceId = null;
	public bool $readonly = false;

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

	// Same workaround as api's AuthApi::jwtDecode(): php-jwt's decode failures aren't
	// MagratheaApiExceptions, so they'd otherwise come back as HTTP 200 with success:false.
	public function jwtDecode($token) {
		$secret = $this->GetSecret();
		try {
			return JWT::decode($token, new Key($secret, $this->jwtEncodeType));
		} catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $ex) {
			throw new MagratheaApiException("Invalid token", 401);
		}
	}

	// POST /login
	public function Login() {
		$post = $this->GetPost();
		$userId = $post["userId"] ?? null;
		$secret = $post["secret"] ?? null;
		if (!is_string($userId) || !is_string($secret) || !ServiceUsers::Validate($userId, $secret)) {
			throw new MagratheaApiException("Invalid credentials", 401);
		}
		return [ "token" => $this->jwtEncode([ "userId" => $userId, "iat" => time() ]) ];
	}

	/**
	 * Base authorization: valid Bearer token whose service still exists in config.json.
	 * Removing a service from config.json revokes its tokens.
	 */
	public function IsService($params = []): bool {
		$token = $this->getTokenByType("Bearer");
		if (!$token) throw new MagratheaApiException("Missing or invalid Authorization header", 401);
		$payload = $this->jwtDecode($token);
		$userId = $payload->userId ?? null;
		if (!is_string($userId) || !ServiceUsers::Exists($userId)) {
			throw new MagratheaApiException("Invalid token", 401);
		}
		$this->userInfo = $payload;
		$this->serviceId = $userId;
		$this->readonly = ServiceUsers::IsReadonly($userId);
		return true;
	}
}
