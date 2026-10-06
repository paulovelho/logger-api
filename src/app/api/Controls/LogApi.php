<?php
namespace logger;

use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;

/** The service-facing routes: write to and read back the caller's own logs/errors. */
class LogApi extends MagratheaApiControl {

	public function __construct(private LoggerAuth $auth) {}

	/**
	 * The raw body as a JSON object. Decoded as objects (not GetPost()'s arrays) so `{}` and `[]`
	 * inside it are stored as sent. An empty body counts as `{}`, as it did in Node.
	 */
	private function Body(): \stdClass {
		$raw = trim((string)file_get_contents("php://input"));
		if ($raw === "") return new \stdClass();
		$body = json_decode($raw);
		if (!($body instanceof \stdClass)) {
			throw new MagratheaApiException("Body must be a JSON object", 400);
		}
		return $body;
	}

	// POST /log
	public function Create($params = false) {
		return LogControl::Write(LogControl::LOGS, $this->auth->serviceId, $this->Body());
	}

	// POST /error
	public function Error($params = false) {
		return LogControl::Write(LogControl::ERRORS, $this->auth->serviceId, $this->Body());
	}

	// GET /report — only the caller's own logs
	public function Report($params = false) {
		return LogControl::Search(LogControl::LOGS, $this->auth->serviceId, $_GET);
	}

	// GET /errors — only the caller's own errors
	public function Errors($params = false) {
		return LogControl::Search(LogControl::ERRORS, $this->auth->serviceId, $_GET);
	}
}
