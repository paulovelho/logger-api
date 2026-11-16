<?php

use logger\LoggerApi;

// The API answers its own errors. Anything that escapes it (a failed bootstrap, a missing class) is
// logged and gets a generic 500 in the API's envelope: never print the exception, it can carry
// paths, SQL or credentials.
try {
	include("_inc.php");
	$api = new LoggerApi();
	$api->Run();
} catch (\Throwable $ex) {
	error_log("logger: ".get_class($ex).": ".$ex->getMessage()." in ".$ex->getFile().":".$ex->getLine());
	if (!headers_sent()) {
		http_response_code(500);
		header("Content-Type: application/json");
	}
	echo json_encode([ "success" => false, "data" => [ "message" => "Internal server error" ] ]);
}
