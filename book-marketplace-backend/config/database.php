<?php
/**
 * Database connection (PDO / MySQL).
 * LOCAL: uses defaults (root, no password, 127.0.0.1)
 * VERCEL + TiDB Cloud: set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * TiDB Cloud only accepts encrypted (TLS) connections. PHP's MySQL driver
 * turns TLS on when it is given a CA certificate, so we pass it
 * isrgrootx1.pem (the Let's Encrypt root that signs TiDB's certificate).
 */

// Never let PHP warnings/notices print into the JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

function get_env_or(string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/* PHP 8.4+ uses Pdo\Mysql::ATTR_*, older PHP uses PDO::MYSQL_ATTR_* */
function mysql_attr(string $name): ?int {
    if (defined("Pdo\\Mysql::ATTR_{$name}")) return constant("Pdo\\Mysql::ATTR_{$name}");
    if (defined("PDO::MYSQL_ATTR_{$name}"))  return constant("PDO::MYSQL_ATTR_{$name}");
    return null;
}

$dbHost = get_env_or('DB_HOST', '127.0.0.1');
$dbPort = get_env_or('DB_PORT', '3306');
$dbName = get_env_or('DB_NAME', 'book_marketplace');
$dbUser = get_env_or('DB_USER', 'root');
$dbPass = get_env_or('DB_PASS', '');

$isRemote = !in_array($dbHost, ['127.0.0.1', 'localhost'], true);

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 10,
];

if ($isRemote) {
    /* First choice: the cert shipped with the project; otherwise the
       server's own CA bundle (paths used by Vercel / common Linux images) */
    $caCandidates = [
        __DIR__ . '/isrgrootx1.pem',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/ssl/cert.pem',
    ];
    $caFile = null;
    foreach ($caCandidates as $candidate) {
        if (is_readable($candidate)) { $caFile = $candidate; break; }
    }

    $caAttr = mysql_attr('SSL_CA');
    if ($caFile !== null && $caAttr !== null) {
        $pdoOptions[$caAttr] = $caFile;
    }
    $verifyAttr = mysql_attr('SSL_VERIFY_SERVER_CERT');
    if ($verifyAttr !== null) {
        $pdoOptions[$verifyAttr] = true;
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
