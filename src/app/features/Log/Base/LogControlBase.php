<?php
namespace logger\Log\Base;

use Magrathea\MagratheaModelControl;

class LogControlBase extends MagratheaModelControl {
	protected static $modelNamespace = "logger\Log";
	protected static $modelName = "Log";
	protected static $dbTable = "logger_logs";
}
