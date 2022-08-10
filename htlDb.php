<?php
/*
@author Arzet Ro, 2022 <arzeth0@gmail.com>

@license MIT No Attribution: https://spdx.org/licenses/MIT-0.html

Source code: https://github.com/arzeth/sugoi-web
*/
// badly tested pre-alpha version
ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);
{
	if (isset($_SERVER['HTTP_REFERER']) && !isset($_SERVER['HTTP_ORIGIN']))
	{
		if (preg_match('@^https?://[^/]+@i', $_SERVER['HTTP_REFERER'], $matches))
		{
			$_SERVER['HTTP_ORIGIN'] = $matches[0];
		}
	}
}
header('Access-Control-Allow-Origin: ' . (@$_SERVER['HTTP_ORIGIN'] ?: '*'));
header('Access-Control-Allow-Methods: GET, PUT, POST');
header('Access-Control-Allow-Credentials: True');
header('Access-Control-Allow-Headers: Access-Control-Allow-Headers, Pragma, Cache-Control, Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers');
header('Vary: Origin');
$name = @$_GET['name'] ?: @$_GET['title'] ?: @$_GET['game'] ?: @$_GET['vn'] ?: @$_GET['VN'] ?: 'natsukoi';
if (!preg_match('/^[a-zA-Z0-9_\\-]+$/', $name)) die('only -_a-zA-Z0-9 are allowed in ?name=');
$_POST = json_decode(file_get_contents("php://input"), true);
if (!function_exists('str_starts_with')) {
	function str_starts_with (string $haystack, string $needle): bool {
		return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}
$IS_READONLY_MODE = str_starts_with(@$_POST['fn'], 'get');
$dbFilePath = __DIR__ . '/' . 'htr__' . $name  . '.db';
if ($IS_READONLY_MODE && !is_readable($dbFilePath))
{
	die('null');
}
$db = new SQLite3(
	$dbFilePath,
	$IS_READONLY_MODE
	? SQLITE3_OPEN_READONLY
	: SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE
);
$possibleLangs = [
	'am',
	'ar',
	'arz',
	'be',
	'bn',
	'cs',
	'da',
	'de',
	'el',
	'en',
	'eo',
	'es',
	'fa',
	'fi',
	'fr',
	'he',
	'hi',
	'hu',
	'hy',
	'id',
	'interslv',
	'it',
	'ja',
	'jbo',
	'jv',
	'ka',
	'ko',
	'la',
	'lt',
	'ms',
	'nl',
	'no',
	'pl',
	'pt_BR',
	'pt_PT',
	'qu',
	'ro',
	'ru',
	'sa',
	'sl',
	'sr',
	'sv',
	'sw',
	'ta',
	'th',
	'tl',
	'tr',
	'tt',
	'uk',
	'vi',
	'yue',
	'zh_Hans',
	'zh_Hant',
];

if ($IS_READONLY_MODE)
{
	if ($db->query(<<<SQL
SELECT COUNT(*)
FROM pragma_table_info('$name')
SQL
	)->fetchArray(SQLITE3_NUM)[0] === 0)
	{
		die('null');
	}
}
else
{
	$db->query(<<<SQL
CREATE TABLE IF NOT EXISTS "$name" (
	"sourceTxt" TEXT NOT NULL,
	"sourceId" CHAR(12) NOT NULL,
	"recordCreatedOrUpdatedOn" UNSIGNED BIGINT(13) NOT NULL,
	PRIMARY KEY(sourceId)
)
SQL
	);
	$db->query(<<<SQL
CREATE INDEX IF NOT EXISTS sourceTxt_idx on "$name" (sourceTxt)
SQL
	);
}

if (
	(
		@$_POST['fn'] === 'getBySourceTxt'
		||
		@$_POST['fn'] === 'getBySourceId'
		||
		@$_POST['fn'] === 'getBySourceIdOrSourceTxt'
	)
)
{
	$whichLangsToGet = (
		$_POST['whichLangsToGet'] === '*' || $_POST['whichLangsToGet'] === 'all'
		? true
		: $_POST['whichLangsToGet']// json array
	);
	$sourceTxt = @$_POST['sourceTxt'];
	$sourceId = @$_POST['sourceId'];
	if ($_POST['fn'] === 'getBySourceId' || $_POST['fn'] === 'getBySourceIdOrSourceTxt')
	{
		$query = $db->prepare(<<<SQL
SELECT * FROM "$name"
WHERE sourceId=:sourceId
SQL
		);
		$query->bindValue(':sourceId', $sourceId);
		$ret = $query->execute();
		$ret = $ret->fetchArray(SQLITE3_ASSOC);
	}
	if ($_POST['fn'] === 'getBySourceTxt' || ($_POST['fn'] === 'getBySourceIdOrSourceTxt' && $ret === false))
	{
		$query = $db->prepare(<<<SQL
SELECT * FROM "$name"
WHERE sourceTxt=:sourceTxt
SQL
		);
		$query->bindValue(':sourceTxt', $sourceTxt);
		$ret = $query->execute();
		$ret = $ret->fetchArray(SQLITE3_ASSOC);
	}

	if ($ret === false)
	{
		die('null');
	}
	if ($whichLangsToGet === true)
	{
		unset($ret['sourceId']);
		unset($ret['sourceTxt']);
		unset($ret['recordCreatedOrUpdatedOn']);
	}
	else
	{
		foreach ($ret as $col => $value)
		{
			if (!in_array($col, $whichLangsToGet, true)) unset($ret[$col]);
		}
	}

	die(json_encode($ret, JSON_UNESCAPED_UNICODE));
}

elseif (
	@$_POST['fn'] === 'set'
)
{
	if (
		!isset($_POST['sourceTxt']) ||
		!isset($_POST['sourceId']) ||
		!isset($_POST['recordCreatedOrUpdatedOn']) ||
		!isset($_POST['resultLang']) ||
		!isset($_POST['resultTxt'])
	)
	{
		die('not all request fields are set');
	}
	//todo sanitize resultLang
	$sourceTxt = $_POST['sourceTxt'];
	$sourceId = $_POST['sourceId'];
	$recordCreatedOrUpdatedOn = (int)$_POST['recordCreatedOrUpdatedOn'];
	$resultLang = $_POST['resultLang'];
	$resultTxt = $_POST['resultTxt'];

	if ($db->query(<<<SQL
SELECT COUNT(*)
FROM pragma_table_info('$name')
WHERE "name"="$resultLang"
SQL
	)->fetchArray(SQLITE3_NUM)[0] !== 1)
	{
		$db->query(<<<SQL
	ALTER TABLE "$name"
	ADD "$resultLang" TEXT
SQL
		);
	}

	//$db->enableExceptions(true);
	$query = $db->prepare(<<<SQL
UPDATE OR IGNORE "$name"
SET
	"recordCreatedOrUpdatedOn"=:recordCreatedOrUpdatedOn,
	"$resultLang"=:$resultLang
WHERE
	"sourceId"=:sourceId
SQL
	);
	$query->bindValue(':recordCreatedOrUpdatedOn', $recordCreatedOrUpdatedOn, SQLITE3_INTEGER);
	$query->bindValue(":$resultLang", $resultTxt, SQLITE3_TEXT);
	//$query->bindValue(':sourceTxt', $sourceTxt);
	$query->bindValue(':sourceId', $sourceId, SQLITE3_TEXT);
	$query->execute();

	$query = $db->prepare(<<<SQL
INSERT OR IGNORE INTO "$name"
	 ("sourceTxt", "sourceId", "recordCreatedOrUpdatedOn", "$resultLang")
VALUES (:sourceTxt, :sourceId, :recordCreatedOrUpdatedOn, :$resultLang)
SQL
	);
	$query->bindValue(':sourceTxt', $sourceTxt, SQLITE3_TEXT);
	$query->bindValue(':sourceId', $sourceId, SQLITE3_TEXT);
	$query->bindValue(':recordCreatedOrUpdatedOn', $recordCreatedOrUpdatedOn, SQLITE3_INTEGER);
	$query->bindValue(":$resultLang", $resultTxt, SQLITE3_TEXT);
	$query->execute();


	if (1) // debugging
	{
		$query = $db->prepare(<<<SQL
SELECT * FROM "$name"
WHERE sourceId=:sourceId
SQL
		);
		$query->bindValue(':sourceId', $sourceId, SQLITE3_TEXT);
		//$query->bindValue(':sourceTxt', $sourceTxt);
		$ret = $query->execute();
		$ret = $ret->fetchArray(SQLITE3_ASSOC);
		die(var_export($ret, true));
	}
}
$db->close();
