<?php
namespace logger\Log;

/** A row of `logger_logs`. Written through LogControl::Write(). */
class Log extends \logger\Log\Base\LogBase {

	public function __construct($id=0){
		parent::__construct($id);
	}

}
