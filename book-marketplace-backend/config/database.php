<?php
/**
 * Database connection (PDO / MySQL).
 * LOCAL: uses defaults (root, no password, 127.0.0.1)
 * VERCEL + TiDB: set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS in Vercel env vars
 * TiDB Cloud requires TLS — we use MYSQL_ATTR_SSL_MODE instead of a cert file
 */
function get_env_or(string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$dbHost = get_env_or('DB_HOST', '127.0.0.1');
$dbPort = get_env_or('DB_PORT', '3306');
$dbName = get_env_or('DB_NAME', 'book_marketplace');
$dbUser = get_env_or('DB_USER', 'root');
$dbPass = get_env_or('DB_PASS', '');

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$pdo = null;

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 10,
];

/*
 * TiDB Cloud (and any remote host that is NOT localhost) requires TLS.
 * We enable SSL without a cert file — TiDB's public endpoint is signed
 * by a trusted CA that PHP's OpenSSL already knows, so no .pem needed.
 * On localhost the SSL constants may not exist, so we guard with defined().
 */
$isTiDB = ($dbHost !== '127.0.0.1' && $dbHost !== 'localhost');

if ($isTiDB) {
    /* Try the modern PDO SSL mode constant first (PHP 8.1+) */
    if (defined('PDO::MYSQL_ATTR_SSL_MODE')) {
        // 2 = SSL_MODE_REQUIRED without verifying the CA cert file
        $pdoOptions[PDO::MYSQL_ATTR_SSL_MODE] = 2;
    }
    /* Always set VERIFY_SERVER_CERT to false — TiDB's cert is valid but
       the hostname in the cert can differ from the gateway hostname */
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    /* Fall back to the Aiven/PlanetScale pem approach only if the file exists */
    $sslCert = __DIR__ . '/isrgrootx1.pem';
    if (file_exists($sslCert) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $sslCert;
    }
}

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
