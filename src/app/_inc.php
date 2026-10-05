<?php

require __DIR__."/../vendor/autoload.php";

try {
	Magrathea2\MagratheaPHP::Instance()
		->MinVersion("2.3.3")
		->AppPath(realpath(dirname(__FILE__)))
		->AppNamespace('logger')
		->AddRootCodeFolder(
			"api",
			"api/Authentication",
			"api/Controls",
			"shared",
		)
		->AddFeature("Log")
		->Prod()
		->Load();
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
