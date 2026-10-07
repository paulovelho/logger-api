<?php
namespace logger;

use Magrathea2\MagratheaApi;
use Magrathea2\MagratheaPHP;

class LoggerApi extends MagratheaApi {

	const PUBLIC = false;
	const SERVICE = "IsService";
	const ACTIVE = "IsActive";
	const ADMIN = "IsAdmin";

	private LoggerAuth $auth;

	public function __construct() {
		$this->Initialize();
	}

	public function Initialize() {
		MagratheaPHP::Instance()->StartDb();
		$this->Cors();
		$this->SetAuth();
		$this->General();
		$this->Logs();
		$this->Admin();
	}

	/** Module root (holds config.json, cors-origins.json, version), two levels above the docroot. */
	private function RootFile(string $name): string {
		return MagratheaPHP::Instance()->GetAppRoot()."/../../".$name;
	}

	// Browsers (admin app, dashboards) only get the allowlisted origins back; servers don't care.
	// A missing or invalid cors-origins.json just means no Access-Control-Allow-Origin header.
	private function Cors() {
		$json = @file_get_contents($this->RootFile("cors-origins.json"));
		$origins = $json === false ? null : json_decode($json, true);
		$this->Allow(is_array($origins) ? array_values(array_filter($origins, "is_string")) : []);
	}

	private function SetAuth() {
		$this->auth = new LoggerAuth();
		$this->BaseAuthorization($this->auth, self::SERVICE);
		$this->Add("POST", "login", $this->auth, "Login", self::PUBLIC, "Exchange service + secret (config.json) for a token");
		$this->Add("POST", "token", $this->auth, "Token", self::PUBLIC, "Decode a token issued by this instance");
	}

	private function General() {
		$this->HealthCheck(true);
		$this->Add("GET", "version", null, function() {
			$version = @file_get_contents($this->RootFile("version"));
			if ($version === false) throw new \Magrathea2\Exceptions\MagratheaApiException("version file not found", 500);
			return [ "version" => trim($version) ];
		}, self::PUBLIC, "Release version");
	}

	private function Logs() {
		$api = new LogApi($this->auth);
		$this->Add("POST", "log", $api, "Create", self::ACTIVE, "Store any JSON payload");
		$this->Add("POST", "error", $api, "Error", self::ACTIVE, "Store any JSON payload as an error");
		$this->Add("POST", "log/batch", $api, "CreateBatch", self::ACTIVE, "Store 1–100 entries at once, all or nothing");
		$this->Add("POST", "error/batch", $api, "ErrorBatch", self::ACTIVE, "Store 1–100 errors at once, all or nothing");
		$this->Add("GET", "report", $api, "Report", self::SERVICE, "Caller's own logs (from, to, limit, skip)");
		$this->Add("GET", "errors", $api, "Errors", self::SERVICE, "Caller's own errors (from, to, limit, skip)");
	}

	private function Admin() {
		$api = new AdminApi($this->auth);
		$this->Add("GET", "admin/logs", $api, "Logs", self::ADMIN, "All logs (service, from, to, limit, skip)");
		$this->Add("GET", "admin/errors", $api, "Errors", self::ADMIN, "All errors (service, from, to, limit, skip)");
		$this->Add("GET", "admin/services", $api, "Services", self::ADMIN, "Active services with log count + last activity");
		$this->Add("DELETE", "admin/logs", $api, "PurgeLogs", self::ADMIN, "Delete a service's logs older than olderThanDays (default 365)");
		$this->Add("DELETE", "admin/errors", $api, "PurgeErrors", self::ADMIN, "Delete a service's errors older than olderThanDays (default 365)");
	}

	// The vendor sends generic errors (unknown route, non-Magrathea exceptions) as HTTP 200;
	// use the error code as the status when it is one, 500 otherwise. Exceptions that aren't
	// MagratheaApiExceptions (DB failures, mysqli codes like 2002) are logged and answered with a
	// generic message: their message and public fields can carry SQL or connection details.
	public function ReturnError($code=500, $message="", $data=null, $status=200) {
		if ($status == 200) $status = (is_int($code) && $code >= 400 && $code <= 599) ? $code : 500;
		if ($data instanceof \Throwable) {
			$this->LogException($data);
			$data = null;
			if ($status >= 500) $message = "Internal server error";
		}
		return parent::ReturnError($code, $message, $data, $status);
	}

	// The vendor's path for exceptions with code 0 (e.g. MagratheaDBException): HTTP 200 with the
	// exception object as `data`. Send it through ReturnError instead.
	public function ReturnFail($data) {
		if ($data instanceof \Magrathea2\Exceptions\MagratheaApiException) return $this->ReturnApiException($data);
		return $this->ReturnError(500, "Internal server error", $data instanceof \Throwable ? $data : null, 500);
	}

	// Best effort: an unwritable logs_path must not turn the JSON error into an HTML page
	private function LogException(\Throwable $ex): void {
		try {
			\Magrathea2\Logger::Instance()->LogError($ex);
		} catch (\Throwable $ignored) {}
	}
}
