<?php
/**
 * Database connection (PDO / MySQL).
 * Reads credentials from environment variables.
 *   DB_HOST=127.0.0.1  DB_PORT=3306
 *   DB_NAME=book_marketplace  DB_USER=root  DB_PASS=
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

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
} catch (PDOException $e) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Database connection failed.',
        'details' => $e->getMessage(),
    ]);
    exit;
}