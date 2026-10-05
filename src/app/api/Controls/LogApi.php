<?php
namespace logger;

use logger\Log\Log;
use logger\Log\LogControl;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;

class LogApi extends MagratheaApiControl {

	public function __construct(private LoggerAuth $auth) {}

	private function WritableService(): string {
		if ($this->auth->readonly) {
			throw new MagratheaApiException("Service '".$this->auth->serviceId."' is read-only", 403);
		}
		return $this->auth->serviceId;
	}

	private function Body(): array {
		$body = $this->GetPost();
		if (!is_array($body) || count($body) === 0) {
			throw new MagratheaApiException("Body must be a non-empty JSON object", 400);
		}
		return $body;
	}

	private function Store(string $userId, array $data): array {
		$log = Log::Write($userId, $data);
		return [
			"id" => $log->id,
			"timestamp" => LogControl::IsoDate($log->timestamp),
		];
	}

	// POST /log
	public function Create($params = false) {
		$userId = $this->WritableService();
		return $this->Store($userId, $this->Body());
	}

	// POST /error — same as /log, tagged as an error unless the caller set its own level
	public function Error($params = false) {
		$userId = $this->WritableService();
		$data = $this->Body();
		if (!array_key_exists("level", $data)) $data["level"] = "error";
		return $this->Store($userId, $data);
	}

	// GET /report — only the caller's own logs
	public function Report($params = false) {
		return LogControl::Search($this->auth->serviceId, $_GET);
	}
}
