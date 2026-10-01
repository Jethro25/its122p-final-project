<?php
/**
 * Database connection (PDO / MySQL).
 * LOCAL: uses defaults (root, no password, 127.0.0.1)
 * VERCEL + TiDB Cloud: set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */

// Suppress ALL deprecation warnings so they never pollute JSON output
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

function get_env_or(string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$dbHost = get_env_or('DB_HOST', '127.0.0.1');
$dbPort = get_env_or('DB_PORT', '3306');
$dbName = get_env_or('DB_NAME', 'book_marketplace');
$dbUser = get_env_or('DB_USER', 'root');
$dbPass = get_env_or('DB_PASS', '');

$isTiDB = ($dbHost !== '127.0.0.1' && $dbHost !== 'localhost');

// Build DSN — append ssl-mode for TiDB Cloud
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
if ($isTiDB) {
    $dsn .= ';ssl-mode=REQUIRED';
}

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 10,
];

// Use the new class-based constant on PHP 8.5, fall back to old one silently
if ($isTiDB) {
    if (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
        $pdoOptions[Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        @$pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
}

$pdo = null;

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $pdoOptions);
} catch (PDOException $e) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Database connection failed.',
        'details' => $e->getMessage(),
        'hint'    => "DB_HOST={$dbHost} DB_PORT={$dbPort} DB_NAME={$dbName} DB_USER={$dbUser}",
    ]);
    exit;
}
