<?php
namespace logger;

use Magrathea2\DB\Database;
use Magrathea2\MagratheaApi;

class LoggerApi extends MagratheaApi {

	const PUBLIC = false;
	const SERVICE = "IsService";

	private LoggerAuth $auth;

	public function __construct() {
		$this->Initialize();
	}

	public function Initialize() {
		\Magrathea2\MagratheaPHP::Instance()->StartDb();
		// Services call server-to-server (CORS doesn't apply) and the admin is same-origin.
		$this->AllowAll();
		$this->SetAuth();
		$this->General();
		$this->Logs();
		$this->Admin();
	}

	private function SetAuth() {
		$this->auth = new LoggerAuth();
		$this->BaseAuthorization($this->auth, self::SERVICE);
		$this->Add("POST", "login", $this->auth, "Login", self::PUBLIC, "Exchange userId + secret (config.json) for a token");
	}

	private function General() {
		$this->Add("GET", "health", null, function() {
			return [
				"status" => "ok",
				"database" => $this->DatabaseStatus(),
			];
		}, self::PUBLIC, "Health check");
	}

	private function DatabaseStatus(): string {
		$db = Database::Instance();
		try {
			$db->OpenConnectionPlease();
		} catch (\Throwable $e) {
			return "fail";
		}
		$db->CloseConnectionThanks();
		return "ok";
	}

	private function Logs() {
		$api = new LogApi($this->auth);
		$this->Add("POST", "log", $api, "Create", self::SERVICE, "Store any JSON payload");
		$this->Add("POST", "error", $api, "Error", self::SERVICE, "Store a JSON payload with level=error");
		$this->Add("GET", "report", $api, "Report", self::SERVICE, "Caller's own logs (from, to, limit, skip)");
	}

	// The vendor sends generic errors (unknown route, non-Magrathea exceptions) as HTTP 200;
	// use the error code as the status when it is one.
	public function ReturnError($code=500, $message="", $data=null, $status=200) {
		if ($status == 200 && is_int($code) && $code >= 400 && $code <= 599) $status = $code;
		return parent::ReturnError($code, $message, $data, $status);
	}

	// Public here; the web server puts basic auth on /admin*
	private function Admin() {
		$api = new AdminApi();
		$this->Add("GET", "admin/logs", $api, "Logs", self::PUBLIC, "All logs (userId, from, to, limit, skip)");
		$this->Add("GET", "admin/services", $api, "Services", self::PUBLIC, "Per-service count + last activity");
	}
}
