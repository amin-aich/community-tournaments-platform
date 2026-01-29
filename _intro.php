<?php
declare(strict_types=1);

// Session Security Settings
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 3));
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Generate CSRF Token if Missing
if (!isset($_SESSION['csrftokken'])) {
    $_SESSION['csrftokken'] = bin2hex(random_bytes(32));
}

// Error Logging (optional)
// ini_set('log_errors', '1');
// ini_set('error_log', __DIR__ . '/php_errors.log');

// Development - show all errors
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Production - log errors but don't display
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/php-error.log');


/********************************************
CONFIG
********************************************/
$dbhost = "localhost";
$dbuser = "root";
$dbpass = "";
$dbname = "mgcd";

$dbprefix = "";

$MAIN_ROOT = "/midnightgamesclub/";
$BASE_DIRECTORY = __DIR__ . "/";

define("BASE_DIRECTORY", $BASE_DIRECTORY);
define("MAIN_ROOT", $MAIN_ROOT);

include_once(BASE_DIRECTORY . "_functions.php");

// DB Connection
$mysqli = new btmysql($dbhost, $dbuser, $dbpass, $dbname);
$mysqli->set_tablePrefix($dbprefix);

define("FULL_SITE_URL", getHTTP() . $_SERVER['SERVER_NAME'] . MAIN_ROOT);

$IP_ADDRESS = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];


/******************************************** 
EXTRA
********************************************/

// Fetch website info
$websiteInfo = [];
$result = $mysqli->query("SELECT name, value FROM {$dbprefix}websiteinfo");
while ($row = $result->fetch_assoc()) {
    $websiteInfo[$row['name']] = $row['value'];
}

// Clan Name
$DOMAIN_NAME = $websiteInfo['domainnname'] ?? 'Dual Masters';

// Timezone
date_default_timezone_set($websiteInfo['default_timezone'] ?? 'UTC');

if (!defined('API_KEY')) {
    // generate with: php -r "echo bin2hex(random_bytes(32));"
    define('API_KEY', 'paste_a_long_random_hex_string_here_64chars_or_more');
}

