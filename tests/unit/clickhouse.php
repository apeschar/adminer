#!/usr/bin/env php
<?php
namespace Adminer;

require __DIR__ . "/../../adminer/include/errors.inc.php";
require __DIR__ . "/../../adminer/include/functions.inc.php";
require __DIR__ . "/../../adminer/include/db.inc.php";
require __DIR__ . "/../../adminer/include/driver.inc.php";
$_GET["clickhouse"] = "";
require __DIR__ . "/../../plugins/drivers/clickhouse.php";
define('Adminer\JUSH', 'clickhouse');

$errors = 0;
function check($actual, $expected, string $message): void {
	global $errors;
	if ($actual !== $expected) {
		echo "$message\n";
		$errors++;
	}
}

$bytes = implode('', array_map('chr', range(0, 255)));
$escaped = '';
foreach (str_split($bytes) as $byte) {
	$escaped .= (ord($byte) < 32 ? sprintf('\\u%04x', ord($byte))
		: ($byte == '"' || $byte == '\\' ? '\\' . $byte : $byte));
}
check(clickhouse_decode_json('{"data":[["' . $escaped . '"]]}'), array('data' => array(array($bytes))), 'All 256 bytes must survive JSON.');
$unicode = json_encode(array('a' => "é\u{2028}😀", 'b' => '\\u0000', 'c' => '"', 'nested' => array(null, true, 7, array('0' => 'x'))));
check(clickhouse_decode_json($unicode), json_decode($unicode, true), 'Unicode escapes, surrogate pairs, backslashes and object keys.');
$mixed = '{"binary":"' . "\xff" . '","unicode":' . $unicode . '}';
check(clickhouse_decode_json($mixed), array('binary' => "\xff", 'unicode' => json_decode($unicode, true)), 'Decode Unicode escapes alongside invalid UTF-8.');
foreach (array('{"a":"\uD800"}', '{"a":"\uDC00"}', '{"a":"\q"}', "{\"a\":\"\0\"}", '{"a":', '{"a":"unterminated}') as $json) {
	check(clickhouse_decode_json($json), null, 'Reject malformed JSON: ' . bin2hex($json));
	check(clickhouse_decode_json('["' . "\xff" . '",' . $json . ']'), null, 'Reject malformed JSON alongside invalid UTF-8.');
}

$binary = "\xff\x00A\x00";
$result = new Result(array(
	'meta' => array(array('name' => 'id', 'type' => 'Nullable(FixedString(4))')),
	'data' => array(array($binary), array(null), array("a\0\0\0")),
	'rows' => 3,
));
check($result->fetch_row(), array($binary), 'FixedString trailing zero bytes are data.');
check($result->fetch_row(), array(null), 'Nullable FixedString stays null.');
check($result->fetch_row(), array("a\0\0\0"), 'Keep text padding too, for exact predicates.');

$driver = (new \ReflectionClass(Driver::class))->newInstanceWithoutConstructor();
Driver::$instance = $driver;
Db::$instance = new Db;
$field = array('type' => 'FixedString', 'full_type' => 'Nullable(FixedString(4))');
foreach (array($binary, "a\0", "\x1B[31m", "\x7F", "a\rb") as $value) {
	check($driver->binaryInput($value, $field), 'unhex', 'Detect binary strings.');
}
foreach (array(null, '', '0x1234', 'hello', 'café 😀') as $value) {
	check($driver->binaryInput($value, $field), '', 'Printable text and null are not binary.');
}
parse_str('where[id]=' . url_escape($binary), $where);
$where['null'] = array();
check(where($where, array('id' => $field)), idf_escape('id') . " = unhex('ff004100')", 'Edit link matches the complete binary value.');
check($driver->quoteBinary($bytes), "unhex('" . bin2hex($bytes) . "')", 'Binary SQL literal preserves every byte.');

exit($errors ? 1 : 0);
