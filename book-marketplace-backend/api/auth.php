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

        // MySQL native prepared statements do not reliably allow the same
        // named placeholder to appear more than once in a statement.
        // Use two parameters for the username/email comparison.
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
        /* ── RATE LIMITING ────────────────────────────────────────────────
           3 wrong passwords are allowed. The 4th wrong password locks the
           account by setting USER.status = 'Locked' in the database, so the
           lock survives refreshes, new browsers and new devices. Only an
           Admin can unlock it (Admin → Users → Unlock).
           ──────────────────────────────────────────────────────────────── */
        $maxAttempts = 3;
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
        $lockedResponse = function (string $username) use ($maxAttempts): void {
            Response::json([
                'error'        => 'Your account is locked because of too many incorrect password attempts. Please contact an administrator to unlock it.',
                'locked'       => true,
                'username'     => $username,
                'max_attempts' => $maxAttempts,
            ], 423);
        };

        if (!$user) Response::error('Invalid username/email or password.', 401);

        if ($user['status'] === 'Locked') $lockedResponse((string) $user['username']);

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

        if (!$valid) {
            // Only Active accounts are counted; Suspended/Banned get the plain error.
            if ($user['status'] !== 'Active') Response::error('Invalid username/email or password.', 401);

            $uid = (int) $user['user_id'];
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
                $lockedResponse((string) $user['username']);
            }

            $finalWarning = ($failed === $maxAttempts);
            Response::json([
                'error'         => $finalWarning
                    ? 'Incorrect password. This was your last allowed attempt — one more incorrect password will lock your account.'
                    : 'Incorrect password.',
                'locked'        => false,
                'attempts_used' => $failed,
                'max_attempts'  => $maxAttempts,
                'final_warning' => $finalWarning,
            ], 401);
        }

        if ($user['status'] !== 'Active') Response::error('This account is not active and cannot sign in.', 403);

        // Successful sign-in clears the failed-attempt counter.
        $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')->execute(['uid' => (int) $user['user_id']]);

        $token = issue_auth_token($user);
        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
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

        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
            'user' => public_user($user),
        ], 201);
    }

    if ($action === 'validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_authenticated_user($pdo);
        Response::json(['authenticated' => true, 'user' => public_user($user)]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        revoke_auth_token(bearer_token_from_request());
        Response::json(['authenticated' => false, 'message' => 'Session revoked.']);
    }

    Response::error('Unknown authentication action.', 404);
} catch (PDOException $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
} catch (Throwable $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
}
