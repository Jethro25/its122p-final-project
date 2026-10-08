<?php
declare(strict_types=1);

/**
 * Server-side session management backed by the database
 * (LIBROWSE_SESSIONS table; works on Vercel's read-only filesystem).
 *
 * The browser only holds a random token. The server stores a SHA-256 hash
 * of it plus the user, role and times, and decides on EVERY request whether
 * the session is still valid. The browser can show a warning, but the
 * server is the one that ends a session.
 *
 * Two timeouts (both set in lib/config.php):
 *   • Idle timeout     — no user activity for SESSION_IDLE_MINUTES (default 30).
 *   • Absolute timeout — SESSION_MAX_HOURS after sign-in (default 8), even if active.
 *
 * "Activity" = a request the user caused. Background auto-refresh requests
 * send `X-Librowse-Background: 1` and do NOT keep the session alive.
 */

function ensure_sessions_table(PDO $pdo): void
{
    static $done = false;          // only once per request (saves database round trips)
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `LIBROWSE_SESSIONS` (
            `token_hash` VARCHAR(64) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `role` VARCHAR(20) NOT NULL,
            `created_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`token_hash`),
            INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        // Table may already exist or no permission — continue
    }
    ensure_column($pdo, 'LIBROWSE_SESSIONS', 'revoked_at');
    ensure_column($pdo, 'LIBROWSE_SESSIONS', 'last_seen_at');
    ensure_column($pdo, 'USER', 'deleted_at');
}

function issue_auth_token(array $user): string
{
    global $pdo;
    ensure_sessions_table($pdo);

    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify('+' . LIBROWSE_SESSION_TTL . ' seconds');

    // Expired sessions are kept for logging; they simply stop working.

    $stmt = $pdo->prepare(
        "INSERT INTO `LIBROWSE_SESSIONS` (token_hash, user_id, role, created_at, expires_at, last_seen_at)
         VALUES (:hash, :user_id, :role, :created_at, :expires_at, :seen)
         ON DUPLICATE KEY UPDATE expires_at = :expires_at2, revoked_at = NULL"
    );
    $stmt->execute([
        'hash'        => $hash,
        'user_id'     => (int) $user['user_id'],
        'role'        => (string) $user['role'],
        'created_at'  => $now->format('Y-m-d H:i:s'),
        'expires_at'  => $expires->format('Y-m-d H:i:s'),
        'expires_at2' => $expires->format('Y-m-d H:i:s'),
        'seen'        => $now->format('Y-m-d H:i:s'),
    ]);

    return $token;
}

function bearer_token_from_request(): ?string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) ? trim($matches[1]) : null;
}

function current_authenticated_user(PDO $pdo): ?array
{
    static $cache = [];            // same request asks more than once — only query the database once
    $token = bearer_token_from_request();
    if ($token && array_key_exists($token, $cache)) return $cache[$token];
    return $cache[(string) $token] = lookup_authenticated_user($pdo);
}

function lookup_authenticated_user(PDO $pdo): ?array
{
    ensure_sessions_table($pdo);

    $token = bearer_token_from_request();
    if (!$token) return null;

    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        "SELECT user_id, role, revoked_at,
                TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS max_left,
                TIMESTAMPDIFF(SECOND, COALESCE(last_seen_at, created_at), UTC_TIMESTAMP()) AS idle_for
         FROM `LIBROWSE_SESSIONS` WHERE token_hash = :hash LIMIT 1"
    );
    $stmt->execute(['hash' => $hash]);
    $session = $stmt->fetch();

    if (!$session || $session['revoked_at'] !== null) return null;

    // Absolute timeout: the session has reached its maximum lifetime
    if ((int) $session['max_left'] <= 0) return null;

    // Idle timeout: decided here on the server, not by a browser timer
    if ((int) $session['idle_for'] >= LIBROWSE_IDLE_TIMEOUT) {
        $pdo->prepare('UPDATE `LIBROWSE_SESSIONS` SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :hash AND revoked_at IS NULL')
            ->execute(['hash' => $hash]);
        log_event('session_idle_timeout', (int) $session['user_id'], ['idle_minutes' => intdiv((int) $session['idle_for'], 60)]);
        $GLOBALS['librowse_session_expired_reason'] = 'idle';
        return null;
    }

    // A real user action resets the idle timer; background auto-refresh does not
    $background = ($_SERVER['HTTP_X_LIBROWSE_BACKGROUND'] ?? '') === '1';
    $idleLeft = LIBROWSE_IDLE_TIMEOUT - (int) $session['idle_for'];
    if (!$background) {
        $pdo->prepare('UPDATE `LIBROWSE_SESSIONS` SET last_seen_at = UTC_TIMESTAMP() WHERE token_hash = :hash')
            ->execute(['hash' => $hash]);
        $idleLeft = LIBROWSE_IDLE_TIMEOUT;
    }
    // Lets the page warn "your session will expire in 2 minutes" (display only)
    if (!headers_sent()) {
        header('X-Session-Idle-Remaining: ' . min($idleLeft, (int) $session['max_left']));
        header('X-Session-Max-Remaining: ' . (int) $session['max_left']);
    }

    $stmt2 = $pdo->prepare(
        'SELECT user_id, username, email, role, status, permission
         FROM `USER` WHERE user_id = :id AND deleted_at IS NULL LIMIT 1'
    );
    $stmt2->execute(['id' => (int) $session['user_id']]);
    $user = $stmt2->fetch();

    if (!$user || $user['status'] !== 'Active' || $user['role'] !== (string) $session['role']) {
        revoke_auth_token($token);
        return null;
    }

    if (is_string($user['permission'] ?? null)) {
        $decoded = json_decode($user['permission'], true);
        $user['permission'] = is_array($decoded) ? $decoded : [];
    }

    return $user;
}

function require_authenticated_user(PDO $pdo, array $allowedRoles = []): array
{
    $user = current_authenticated_user($pdo);
    if ($user === null) {
        // 401 = not authenticated (no session, or it expired / was signed out)
        $idle = ($GLOBALS['librowse_session_expired_reason'] ?? '') === 'idle';
        Response::error(
            $idle ? 'You were signed out after a period of inactivity. Please sign in again.'
                  : 'Authentication required or session expired.',
            401,
            ['reason' => $idle ? 'idle_timeout' : 'unauthenticated']
        );
    }
    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
        // 403 = signed in, but this role may not do this. Logged for monitoring.
        log_event('permission_denied', (int) $user['user_id'], [
            'role'     => $user['role'],
            'needs'    => $allowedRoles,
            'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
            'endpoint' => basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)),
        ], 'warning');
        Response::error('You are not authorized to perform this action.', 403);
    }
    return $user;
}

function revoke_auth_token(?string $token): void
{
    global $pdo;
    if (!$token || !$pdo) return;
    ensure_sessions_table($pdo);
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("UPDATE `LIBROWSE_SESSIONS` SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :hash AND revoked_at IS NULL");
    $stmt->execute(['hash' => $hash]);
}

/** Signs a user out everywhere (all devices / browsers). Returns how many sessions ended. */
function revoke_all_sessions_for_user(int $userId, ?string $exceptToken = null): int
{
    global $pdo;
    ensure_sessions_table($pdo);
    $sql = 'UPDATE `LIBROWSE_SESSIONS` SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :uid AND revoked_at IS NULL';
    $params = ['uid' => $userId];
    if ($exceptToken) {
        $sql .= ' AND token_hash <> :keep';
        $params['keep'] = hash('sha256', $exceptToken);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
