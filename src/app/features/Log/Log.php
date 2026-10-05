<?php
namespace logger\Log;

class Log extends \logger\Log\Base\LogBase {

	public function __construct($id=0){
		parent::__construct($id);
	}

	/**
	 * Creates and stores a log entry.
	 * The id is a UUIDv7 filled in by CreateInsertQuery(); plain Insert() would overwrite it
	 * with mysqli's insert_id, so InsertWithPk() is used instead.
	 */
	public static function Write(string $userId, array $data): Log {
		$log = new Log();
		$log->user_id = $userId;
		$log->data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$log->timestamp = (new \DateTimeImmutable("now", new \DateTimeZone("UTC")))->format("Y-m-d H:i:s.v");
		$log->InsertWithPk();
		return $log;
	}

}
