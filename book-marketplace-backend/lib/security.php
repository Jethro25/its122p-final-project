<?php
declare(strict_types=1);

/**
 * Server-side session management backed by a small XML registry.
 * This implementation intentionally avoids SimpleXML/DOM so it also works in
 * minimal PHP builds that only provide the libxml library.
 * Only SHA-256 token hashes are persisted; raw bearer tokens never touch disk.
 */
const LIBROWSE_SESSION_TTL = 28800; // 8 hours

function security_xml_path(): string
{
    return dirname(__DIR__) . '/data/security.xml';
}

function ensure_security_xml(): void
{
    $path = security_xml_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (!file_exists($path)) {
        file_put_contents($path, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<security>\n    <sessions/>\n</security>\n", LOCK_EX);
        @chmod($path, 0600);
    }
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function security_sessions(): array
{
    ensure_security_xml();
    $xml = file_get_contents(security_xml_path());
    if ($xml === false || trim($xml) === '') return [];

    preg_match_all('/<session\s+([^>]*?)\s*\/>/i', $xml, $matches);
    $sessions = [];
    foreach ($matches[1] ?? [] as $attributes) {
        $row = [];
        preg_match_all('/([a-z_]+)="([^"]*)"/i', $attributes, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $row[$pair[1]] = html_entity_decode($pair[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (!empty($row['token_hash'])) $sessions[] = $row;
    }
    return $sessions;
}

function save_security_sessions(array $sessions): void
{
    ensure_security_xml();
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<security>',
        '    <sessions>'
    ];

    foreach ($sessions as $session) {
        $attributes = [];
        foreach (['token_hash', 'user_id', 'role', 'created_at', 'expires_at'] as $key) {
            if (isset($session[$key])) {
                $attributes[] = $key . '="' . xml_escape((string) $session[$key]) . '"';
            }
        }
        $lines[] = '        <session ' . implode(' ', $attributes) . '/>';
    }

    $lines[] = '    </sessions>';
    $lines[] = '</security>';
    $content = implode("\n", $lines) . "\n";

    $handle = fopen(security_xml_path(), 'c+');
    if ($handle === false) throw new RuntimeException('Unable to open the XML security registry.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock the XML security registry.');
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $content);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function cleanup_session_rows(array &$sessions): bool
{
    $now = time();
    $before = count($sessions);
    $sessions = array_values(array_filter($sessions, static function (array $session) use ($now): bool {
        $expiresAt = isset($session['expires_at']) ? strtotime($session['expires_at']) : false;
        return $expiresAt === false || $expiresAt > $now;
    }));
    return count($sessions) !== $before;
}

function issue_auth_token(array $user): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify('+' . LIBROWSE_SESSION_TTL . ' seconds');

    $sessions = security_sessions();
    cleanup_session_rows($sessions);
    $sessions[] = [
        'token_hash' => hash('sha256', $token),
        'user_id' => (string) $user['user_id'],
        'role' => (string) $user['role'],
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'expires_at' => $expires->format(DateTimeInterface::ATOM),
    ];
    save_security_sessions($sessions);
    return $token;
}

function bearer_token_from_request(): ?string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) ? trim($matches[1]) : null;
}

function current_authenticated_user(PDO $pdo): ?array
{
    $token = bearer_token_from_request();
    if (!$token) return null;

    $hash = hash('sha256', $token);
    $sessions = security_sessions();
    $changed = cleanup_session_rows($sessions);
    $matched = null;

    foreach ($sessions as $session) {
        if (isset($session['token_hash']) && hash_equals($hash, (string) $session['token_hash'])) {
            $matched = $session;
            break;
        }
    }

    if ($changed) save_security_sessions($sessions);
    if ($matched === null) return null;

    $stmt = $pdo->prepare('SELECT user_id, username, email, role, status, permission FROM `USER` WHERE user_id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $matched['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'Active' || $user['role'] !== (string) $matched['role']) {
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
    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) Response::error('You are not authorized to perform this action.', 403);
    return $user;
}

function revoke_auth_token(?string $token): void
{
    if (!$token) return;
    $hash = hash('sha256', $token);
    $sessions = security_sessions();
    $remaining = [];
    foreach ($sessions as $session) {
        if (!isset($session['token_hash']) || !hash_equals($hash, (string) $session['token_hash'])) {
            $remaining[] = $session;
        }
    }
    cleanup_session_rows($remaining);
    save_security_sessions($remaining);
}
