<?php

use Magrathea2\Admin\AdminManager;

include("_inc.php");

try {
	Magrathea2\MagratheaPHP::Instance()->StartSession();
	AdminManager::Instance()->StartDefault("Logger Admin");
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
