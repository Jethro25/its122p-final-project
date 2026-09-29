<?php
declare(strict_types=1);

/**
 * Local-development CORS and preflight handling must run before the database
 * bootstrap. Otherwise a failed DB connection can prevent CORS headers from
 * reaching the browser and surface only as a generic NetworkError.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
header('Access-Control-Max-Age: 600');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/dispatch.php';
