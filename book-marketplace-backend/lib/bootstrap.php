<?php
declare(strict_types=1);

/* ── CORS: which websites may call this API from a browser ─────────────
   Allowed: localhost / 127.0.0.1 (development), *.vercel.app (production),
   and the same host the API itself is served from. Any other website gets
   no CORS permission, so its pages can't read our replies. (There is no
   "allow everyone" (*) fallback any more.) */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originHost = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
$serverHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
if ($origin !== '' && (
    preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin) ||
    preg_match('#^https://[a-z0-9\-]+\.vercel\.app$#i', $origin) ||
    ($originHost !== '' && strtolower($originHost) === $serverHost)
)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, Idempotency-Key, X-Librowse-Background');
header('Access-Control-Expose-Headers: X-Session-Idle-Remaining, X-Session-Max-Remaining, Idempotent-Replayed, Retry-After');
header('Access-Control-Max-Age: 600');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
/* Basic hardening headers for every API reply */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* Any error nobody caught becomes a friendly JSON reply, never a PHP
   stack trace. The real error goes to the server log for developers. */
set_exception_handler(function (Throwable $e): void {
    error_log('[librowse] unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    $reply = ['error' => 'Something went wrong on our side. Nothing was changed — please try again.'];
    if (getenv('APP_DEBUG') === '1') $reply['details'] = $e->getMessage();
    echo json_encode($reply);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/soft_delete.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/idempotency.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/dispatch.php';
