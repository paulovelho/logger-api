<?php
namespace logger\ErrorLog;

/** A row of `logger_errors`. Written through LogControl::InsertRows(). */
class ErrorLog extends \logger\ErrorLog\Base\ErrorLogBase {

	public function __construct($id=0){
		parent::__construct($id);
	}

}
