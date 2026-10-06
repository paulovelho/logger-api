<?php
namespace logger;

use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;

/**
 * /admin/* routes, for the admin dashboard (admin.html) and the guia.lol admin app.
 * Every route requires the readonly (admin) credential: LoggerAuth::IsAdmin.
 */
class AdminApi extends MagratheaApiControl {

	public function __construct(private LoggerAuth $auth) {}

	// GET /admin/logs
	public function Logs($params = false) {
		return LogControl::Search(LogControl::LOGS, LogControl::Param($_GET, "service"), $_GET);
	}

	// GET /admin/errors
	public function Errors($params = false) {
		return LogControl::Search(LogControl::ERRORS, LogControl::Param($_GET, "service"), $_GET);
	}

	// GET /admin/services
	public function Services($params = false) {
		return [ "services" => LogControl::Services() ];
	}

	// DELETE /admin/logs?service=&olderThanDays=
	public function PurgeLogs($params = false) {
		return $this->Purge(LogControl::LOGS);
	}

	// DELETE /admin/errors?service=&olderThanDays=
	public function PurgeErrors($params = false) {
		return $this->Purge(LogControl::ERRORS);
	}

	private function Purge(string $table): array {
		$service = LogControl::Param($_GET, "service");
		if ($service === null) throw new MagratheaApiException("service is required", 400);
		// absent → default; present but empty, zero, negative or not a whole number → 400
		$days = $_GET["olderThanDays"] ?? null;
		if ($days === null) {
			$days = LogControl::DEFAULT_PURGE_DAYS;
		} else if (is_string($days) && preg_match('/^[0-9]{1,6}$/', $days) === 1 && (int)$days > 0) {
			$days = (int)$days;
		} else {
			throw new MagratheaApiException("olderThanDays must be a positive number", 400);
		}
		return LogControl::Purge($table, $service, $days, $this->auth->serviceId);
	}
}
