<?php
namespace logger\ErrorLog\Base;

use Magrathea\MagratheaModelControl;

class ErrorLogControlBase extends MagratheaModelControl {
	protected static $modelNamespace = "logger\ErrorLog";
	protected static $modelName = "ErrorLog";
	protected static $dbTable = "logger_errors";
}
