<?php
// Loads the app (Magrathea config, autoloaders) the same way index.php does; LoggerApi starts the DB.
require __DIR__."/../app/_inc.php";
Magrathea2\MagratheaPHP::Instance()->StartDb();
require __DIR__."/LoggerTestCase.php";
