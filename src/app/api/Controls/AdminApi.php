<?php
namespace logger;

use logger\Log\LogControl;
use Magrathea\Exceptions\MagratheaApiException;
use Magrathea\MagratheaApiControl;

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

	// GET /admin/logs/purge?service=&occurredBefore=
	public function PurgeLogsPreview($params = false) {
		return $this->Preview(LogControl::LOGS);
	}

	// GET /admin/errors/purge?service=&occurredBefore=
	public function PurgeErrorsPreview($params = false) {
		return $this->Preview(LogControl::ERRORS);
	}

	// DELETE /admin/logs?service=&olderThanDays=  or  ?service=&occurredBefore=[&maxId=]
	public function PurgeLogs($params = false) {
		return $this->Purge(LogControl::LOGS);
	}

	// DELETE /admin/errors (same params)
	public function PurgeErrors($params = false) {
		return $this->Purge(LogControl::ERRORS);
	}

	private function Preview(string $table): array {
		if (!array_key_exists("occurredBefore", $_GET)) throw new MagratheaApiException("occurredBefore is required", 400);
		return LogControl::PurgeCount($table, self::PurgeService(), LogControl::ClientDate($_GET["occurredBefore"], "occurredBefore"));
	}

	private function Purge(string $table): array {
		$service = self::PurgeService();
		// present but empty counts as present (and fails validation): an empty occurredBefore must
		// never fall back to the 365-day default
		$hasDate = array_key_exists("occurredBefore", $_GET);
		$hasDays = array_key_exists("olderThanDays", $_GET);
		if ($hasDate && $hasDays) throw new MagratheaApiException("Send olderThanDays or occurredBefore, not both", 400);

		$maxId = null;
		if (array_key_exists("maxId", $_GET)) {
			$maxId = $_GET["maxId"];
			if (!is_string($maxId) || preg_match('/^[1-9][0-9]{0,17}$/', $maxId) !== 1) {
				throw new MagratheaApiException("maxId must be a positive whole number", 400);
			}
			$maxId = (int)$maxId;
		}

		if ($hasDate) {
			$cutoff = LogControl::ClientDate($_GET["occurredBefore"], "occurredBefore");
		} else if (!$hasDays) {
			$cutoff = LogControl::DEFAULT_PURGE_DAYS;
		} else {
			// zero, negative, empty or not a whole number → 400
			$days = $_GET["olderThanDays"];
			if (!is_string($days) || preg_match('/^[0-9]{1,6}$/', $days) !== 1 || (int)$days < 1) {
				throw new MagratheaApiException("olderThanDays must be a positive number", 400);
			}
			$cutoff = (int)$days;
		}
		return LogControl::Purge($table, $service, $cutoff, $this->auth->serviceId, $maxId);
	}

	private static function PurgeService(): string {
		$service = LogControl::Param($_GET, "service");
		if ($service === null) throw new MagratheaApiException("service is required", 400);
		return $service;
	}
}
