<?php
// Entry point for tests/clickhouse.spec.js.
chdir(__DIR__ . "/../adminer");
define('Adminer\DIR', "../adminer/");

function adminer_object() {
	include_once "../plugins/drivers/clickhouse.php";
	return new Adminer\Adminer;
}

include "./index.php";
