<?php

// Shared bootstrap: no output and no catch. The entry points (index.php, magrathea.php) handle failures.
require __DIR__."/../vendor/autoload.php";

Magrathea\MagratheaPHP::Instance()
	->MinVersion("3.0.0")
	->AppPath(realpath(dirname(__FILE__)))
	->AppNamespace('logger')
	->AddRootCodeFolder(
		"api",
		"api/Authentication",
		"api/Controls",
		"shared",
	)
	->AddFeature("Log", "ErrorLog")
	->Prod()
	->Load()
	->Connect(); // only stores the credentials: the connection opens on the first query
