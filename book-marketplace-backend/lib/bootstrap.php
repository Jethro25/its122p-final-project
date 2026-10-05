<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

/* Allow localhost (dev) + any *.vercel.app domain (production) */
if ($origin !== '' && (
    preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin) ||
    preg_match('#^https://[a-z0-9\-]+\.vercel\.app$#i', $origin)
)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
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
require_once __DIR__ . '/soft_delete.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/dispatch.php';
