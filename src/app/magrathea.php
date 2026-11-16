<?php

use Magrathea\Admin\AdminManager;

// Vendor admin. Like index.php: a failure is logged and answered with a plain 500, never printed.
try {
	include("_inc.php");
	Magrathea\MagratheaPHP::Instance()->StartSession();
	AdminManager::Instance()->StartDefault("Logger Admin");
} catch (\Throwable $ex) {
	error_log("logger admin: ".get_class($ex).": ".$ex->getMessage()." in ".$ex->getFile().":".$ex->getLine());
	if (!headers_sent()) {
		http_response_code(500);
		header("Content-Type: text/plain; charset=utf-8");
	}
	echo "Internal server error";
}
