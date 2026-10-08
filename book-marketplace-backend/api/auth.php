<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function auth_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) Response::error('Request body must be valid JSON.', 400);
    return $decoded;
}

function public_user(array $user): array
{
    $permission = $user['permission'] ?? [];
    if (is_string($permission)) {
        $permission = json_decode($permission, true) ?: [];
    }
    return [
        'user_id' => (int) $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'status' => $user['status'],
        'permission' => $permission,
    ];
}

ensure_column($pdo, 'USER', 'deleted_at');
$action = strtolower((string) ($_GET['action'] ?? ''));

try {
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identifier === '' || $password === '') {
            Response::error('Username/email and password are required.', 422);
        }

        /* ── ONE MESSAGE FOR EVERY FAILED SIGN-IN ────────────────────────
           "Wrong password" vs "no such user" would tell an attacker which
           accounts exist (account enumeration). So unknown usernames, wrong
           passwords and wrong passwords on locked accounts all get exactly
           the same reply. */
        $genericFail = function (): void {
            Response::json([
                'error'  => 'Invalid username/email or password. For your security, an account is locked after '
                          . (LIBROWSE_LOGIN_MAX_ATTEMPTS + 1) . ' incorrect passwords in a row.',
                'locked' => false,
            ], 401);
        };

        /* ── BRUTE-FORCE PROTECTION, LAYER 1: per IP address ─────────────
           Many failed sign-ins from one IP (guessing across many accounts)
           → 429 Too Many Requests for a while. Monitoring in action: the
           system reads its own log and responds automatically. */
        ensure_activity_log_table($pdo);
        $ipCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM `ACTIVITY_LOG`
             WHERE event = 'login_failed' AND ip_address = :ip
               AND created_at > UTC_TIMESTAMP() - INTERVAL :win SECOND"
        );
        $ipCheck->execute(['ip' => client_ip(), 'win' => LIBROWSE_LOGIN_IP_WINDOW]);
        if ((int) $ipCheck->fetchColumn() >= LIBROWSE_LOGIN_IP_LIMIT) {
            log_event('login_rate_limited', null, ['identifier' => substr($identifier, 0, 100)], 'critical');
            header('Retry-After: ' . LIBROWSE_LOGIN_IP_WINDOW);
            Response::error('Too many failed sign-in attempts from your network. Please wait '
                . intdiv(LIBROWSE_LOGIN_IP_WINDOW, 60) . ' minutes and try again.', 429);
        }

        // MySQL native prepared statements do not reliably allow the same
        // named placeholder to appear more than once in a statement.
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, role, status, permission
             FROM `USER`
             WHERE deleted_at IS NULL AND (username = :username_identifier OR LOWER(email) = LOWER(:email_identifier))
             LIMIT 1'
        );
        $stmt->execute([
            'username_identifier' => $identifier,
            'email_identifier' => $identifier,
        ]);
        $user = $stmt->fetch();

        /* ── BRUTE-FORCE PROTECTION, LAYER 2: per account ────────────────
           LOGIN_MAX_ATTEMPTS (default 3) wrong passwords are allowed; the
           next one locks the account (USER.status = 'Locked'), which
           survives refreshes, new browsers and new devices. Only an Admin
           can unlock it; the user can send an unlock request. */
        $maxAttempts = LIBROWSE_LOGIN_MAX_ATTEMPTS;
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `LOGIN_ATTEMPTS` (
                `attempt_id`   INT UNSIGNED AUTO_INCREMENT NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`attempt_id`),
                INDEX `idx_la_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        ensure_column($pdo, 'LOGIN_ATTEMPTS', 'cleared_at');

        if (!$user) {
            // Spend the same time checking a password as for a real account,
            // so response time doesn't reveal whether the username exists.
            password_verify($password, '$2y$10$qJj5v/hVy4wAq/1N.vYu/uGq/hL4hsGrXybLqNLV73YQDhOkM4V/u');
            log_event('login_failed', null, ['identifier' => substr($identifier, 0, 100), 'reason' => 'unknown_account'], 'warning');
            $genericFail();
        }

        $hash = (string) $user['password_hash'];
        $valid = $hash !== '' && password_verify($password, $hash);

        // Compatibility migration for the original seed placeholders.
        // Only for the broken placeholder hashes in the original seed data
        // (they are too short to be real bcrypt hashes). Real hashes never match here.
        if (!$valid && str_starts_with($hash, '$2b$') && strlen($hash) < 60 && $password === 'password') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id');
            $up->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }
        if (!$valid && $hash !== '' && !str_starts_with($hash, '$') && hash_equals($hash, $password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id');
            $up->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }

        $uid = (int) $user['user_id'];

        if (!$valid) {
            log_event('login_failed', $uid, ['reason' => 'wrong_password', 'status' => $user['status']], 'warning');

            // Only Active accounts are counted; Locked/Suspended/Banned just get the generic reply.
            if ($user['status'] !== 'Active') $genericFail();

            $pdo->prepare('INSERT INTO `LOGIN_ATTEMPTS` (user_id, attempted_at) VALUES (:uid, UTC_TIMESTAMP())')
                ->execute(['uid' => $uid]);
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid AND cleared_at IS NULL');
            $countStmt->execute(['uid' => $uid]);
            $failed = (int) $countStmt->fetchColumn();

            if ($failed > $maxAttempts) {
                $lock = $pdo->prepare("UPDATE `USER` SET status = 'Locked' WHERE user_id = :uid");
                try {
                    $lock->execute(['uid' => $uid]);
                } catch (PDOException $e) {
                    // Older schemas lack 'Locked' in the status ENUM — add it, then retry.
                    $pdo->exec("ALTER TABLE `USER` MODIFY `status` ENUM('Active','Suspended','Banned','Pending Verification','Locked') NOT NULL DEFAULT 'Pending Verification'");
                    $lock->execute(['uid' => $uid]);
                }
                // Reset the counter so the user starts fresh once an Admin unlocks them.
                $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')->execute(['uid' => $uid]);
                // A locked account must not stay signed in anywhere else either
                revoke_all_sessions_for_user($uid);
                log_event('account_locked', $uid, ['failed_attempts' => $failed], 'critical');
            }
            $genericFail();
        }

        /* Correct password from here on. Only someone who knows the password
           learns that the account is locked or inactive — no enumeration. */
        if ($user['status'] === 'Locked') {
            log_event('login_blocked_locked', $uid, [], 'warning');
            Response::json([
                'error'        => 'Your account is locked because of too many incorrect password attempts. Please contact an administrator to unlock it.',
                'locked'       => true,
                'username'     => (string) $user['username'],
                'max_attempts' => $maxAttempts,
            ], 423);
        }
        if ($user['status'] !== 'Active') {
            log_event('login_blocked_inactive', $uid, ['status' => $user['status']], 'warning');
            Response::error('This account is not active and cannot sign in.', 403);
        }

        // Successful sign-in clears the failed-attempt counter.
        $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')->execute(['uid' => $uid]);

        $token = issue_auth_token($user);
        log_event('login_success', $uid, ['role' => $user['role']]);
        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
            'idle_timeout' => LIBROWSE_IDLE_TIMEOUT,
            'user' => public_user($user),
        ]);
    }

    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) Response::error('Username must be 3-50 characters and contain only letters, numbers, and underscores.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please provide a valid email address.', 422);

        /* ── Password strength ───────────────────────────────────────────────
           Min 8 chars; needs uppercase, lowercase, digit, special character. */
        $pwErrors = [];
        if (strlen($password) < 8)                     $pwErrors[] = 'at least 8 characters';
        if (!preg_match('/[A-Z]/', $password))          $pwErrors[] = 'an uppercase letter (A–Z)';
        if (!preg_match('/[a-z]/', $password))          $pwErrors[] = 'a lowercase letter (a–z)';
        if (!preg_match('/[0-9]/', $password))          $pwErrors[] = 'a number (0–9)';
        if (!preg_match('/[^A-Za-z0-9]/', $password))  $pwErrors[] = 'a special character (e.g. @, #, !, %)';
        // bcrypt only uses the first 72 bytes of a password
        if (strlen($password) > 72) Response::error('Password must be 72 characters or fewer.', 422);
        if ($pwErrors) {
            Response::error('Password is too weak. It must include: ' . implode(', ', $pwErrors) . '.', 422);
        }

        $check = $pdo->prepare('SELECT user_id FROM `USER` WHERE username = :username OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username' => $username, 'email' => $email]);
        if ($check->fetch()) Response::error('Username or email is already registered.', 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO `USER` (username, email, password_hash, role, status, permission)
             VALUES (:username, :email, :hash, 'Customer', 'Active', '{}')"
        );
        $insert->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);

        $stmt = $pdo->prepare('SELECT user_id, username, email, role, status, permission FROM `USER` WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $pdo->lastInsertId()]);
        $user = $stmt->fetch();
        $token = issue_auth_token($user);
        log_event('account_registered', (int) $user['user_id']);

        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
            'idle_timeout' => LIBROWSE_IDLE_TIMEOUT,
            'user' => public_user($user),
        ], 201);
    }

    if ($action === 'validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_authenticated_user($pdo);
        Response::json([
            'authenticated' => true,
            'idle_timeout'  => LIBROWSE_IDLE_TIMEOUT,
            'user'          => public_user($user),
        ]);
    }

    /* Logout: the SERVER ends the session (revokes the token). Just sending
       the user to the login page would leave the token usable. */
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = bearer_token_from_request();
        $user = $token ? current_authenticated_user($pdo) : null;
        revoke_auth_token($token);
        if ($user) log_event('logout', (int) $user['user_id']);
        Response::json(['authenticated' => false, 'message' => 'Session revoked.']);
    }

    /* Sign out of every device (e.g. "I forgot to log out at the library") */
    if ($action === 'logout_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_authenticated_user($pdo);
        $count = revoke_all_sessions_for_user((int) $user['user_id']);
        log_event('logout_all_devices', (int) $user['user_id'], ['sessions_ended' => $count], 'warning');
        Response::json(['authenticated' => false, 'sessions_ended' => $count, 'message' => 'Signed out of all devices.']);
    }

    Response::error('Unknown authentication action.', 404);
} catch (PDOException $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
} catch (Throwable $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
}
