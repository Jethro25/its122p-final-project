<?php
declare(strict_types=1);

/**
 * Server-side session management backed by the database.
 * Replaces the XML file-based approach which doesn't work on Vercel
 * (read-only filesystem). Sessions are stored in SYSTEM_RECORDS table
 * using the existing schema — no backend changes needed.
 */
const LIBROWSE_SESSION_TTL = 28800; // 8 hours

function ensure_sessions_table(PDO $pdo): void
{
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
        "INSERT INTO `LIBROWSE_SESSIONS` (token_hash, user_id, role, created_at, expires_at)
         VALUES (:hash, :user_id, :role, :created_at, :expires_at)
         ON DUPLICATE KEY UPDATE expires_at = :expires_at2, revoked_at = NULL"
    );
    $stmt->execute([
        'hash'        => $hash,
        'user_id'     => (int) $user['user_id'],
        'role'        => (string) $user['role'],
        'created_at'  => $now->format('Y-m-d H:i:s'),
        'expires_at'  => $expires->format('Y-m-d H:i:s'),
        'expires_at2' => $expires->format('Y-m-d H:i:s'),
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
    ensure_sessions_table($pdo);

    $token = bearer_token_from_request();
    if (!$token) return null;

    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        "SELECT user_id, role FROM `LIBROWSE_SESSIONS`
         WHERE token_hash = :hash AND expires_at > UTC_TIMESTAMP() AND revoked_at IS NULL
         LIMIT 1"
    );
    $stmt->execute(['hash' => $hash]);
    $session = $stmt->fetch();

    if (!$session) return null;

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
    if ($user === null) Response::error('Authentication required or session expired.', 401);
    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
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
