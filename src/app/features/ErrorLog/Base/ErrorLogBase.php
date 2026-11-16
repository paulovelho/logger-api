<?php
namespace logger\ErrorLog\Base;

use Magrathea\MagratheaModel;

class ErrorLogBase extends MagratheaModel {

	public $id, $service, $environment, $data, $timestamp, $occurred_at;

	public function MagratheaStart(){
		$this->dbTable = "logger_errors";
		$this->dbPk = "id";
		$this->dbValues["id"] = "int";
		$this->dbValues["service"] = "string";
		$this->dbValues["environment"] = "string";
		$this->dbValues["data"] = "text";
		$this->dbValues["timestamp"] = "datetime";
		$this->dbValues["occurred_at"] = "datetime";
	}

	public function GetControl() {
		return new \logger\ErrorLog\Base\ErrorLogControlBase();
	}

}
