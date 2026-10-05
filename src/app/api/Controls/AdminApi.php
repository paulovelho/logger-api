<?php
namespace logger;

use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;

/**
 * Backs the static dashboard (admin.html). Open at the PHP level on purpose:
 * /admin* is protected by basic auth in the web server (.htaccess / site.caddy.example).
 */
class AdminApi extends MagratheaApiControl {

	// GET /admin/logs
	public function Logs($params = false) {
		$userId = $_GET["userId"] ?? null;
		if ($userId === "") $userId = null;
		if ($userId !== null && !is_string($userId)) throw new MagratheaApiException("Invalid 'userId'", 400);
		return LogControl::Search($userId, $_GET);
	}

	// GET /admin/services
	public function Services($params = false) {
		return [ "services" => LogControl::Services() ];
	}
}
