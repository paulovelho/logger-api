<?php

die;

include("_inc.php");

Magrathea2\MagratheaPHP::Instance()
	->AppPath(realpath(dirname(__FILE__)))
	->Dev()
	->Load();
Magrathea2\Bootstrap\Start::Instance()->Load();
