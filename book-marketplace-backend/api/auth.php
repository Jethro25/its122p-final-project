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
        'user_id'    => (int) $user['user_id'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'status'     => $user['status'],
        'permission' => $permission,
    ];
}

// ── Ensure schema additions that older databases may not have yet ──────────

ensure_column($pdo, 'USER', 'deleted_at');

/* Ensure 'Locked' is in the USER status ENUM */
$lockCheck = $pdo->query("SHOW COLUMNS FROM `USER` LIKE 'status'")->fetch();
if ($lockCheck && strpos((string) ($lockCheck['Type'] ?? ''), "'Locked'") === false) {
    try {
        $pdo->exec("ALTER TABLE `USER` MODIFY `status` ENUM('Active','Suspended','Banned','Pending Verification','Locked') NOT NULL DEFAULT 'Pending Verification'");
    } catch (PDOException $ignore) {}
}

// ── Email Verifications table (created on first use) ──────────────────────
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `EMAIL_VERIFICATIONS` (
        `verification_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
        `user_id`         INT UNSIGNED NOT NULL,
        `token_hash`      CHAR(64) NOT NULL,
        `expires_at`      DATETIME NOT NULL,
        `used_at`         DATETIME NULL DEFAULT NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`verification_id`),
        UNIQUE KEY `uq_ev_token` (`token_hash`),
        INDEX `idx_ev_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// ── Password Resets table (created on first use) ──────────────────────────
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `PASSWORD_RESETS` (
        `reset_id`    INT UNSIGNED AUTO_INCREMENT NOT NULL,
        `user_id`     INT UNSIGNED NOT NULL,
        `token_hash`  CHAR(64) NOT NULL,
        `expires_at`  DATETIME NOT NULL,
        `used_at`     DATETIME NULL DEFAULT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`reset_id`),
        UNIQUE KEY `uq_pr_token` (`token_hash`),
        INDEX `idx_pr_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// ── Password History table (created on first use) ─────────────────────────
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `PASSWORD_HISTORY` (
        `history_id`    INT UNSIGNED AUTO_INCREMENT NOT NULL,
        `user_id`       INT UNSIGNED NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`history_id`),
        INDEX `idx_ph_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// ── Login Attempts table (created on first use) ───────────────────────────
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

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Creates a verification/reset token: stores its hash in the given table
 * and returns the raw (unhashed) token to embed in the email link.
 */
function store_token(PDO $pdo, string $table, int $userId, string $idColumn, int $expirySeconds = 86400): string
{
    $raw  = bin2hex(random_bytes(32));   // 64-char hex
    $hash = hash('sha256', $raw);
    $exp  = gmdate('Y-m-d H:i:s', time() + $expirySeconds);
    // Invalidate any previous unused tokens for this user in the same table
    $pdo->prepare("UPDATE `{$table}` SET used_at = UTC_TIMESTAMP() WHERE user_id = :uid AND used_at IS NULL")
        ->execute(['uid' => $userId]);
    $pdo->prepare("INSERT INTO `{$table}` (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)")
        ->execute(['uid' => $userId, 'hash' => $hash, 'exp' => $exp]);
    return $raw;
}

/**
 * Validates a submitted raw token against a stored hash.
 * Returns the row on success or null on failure.
 */
function validate_token(PDO $pdo, string $table, string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE token_hash = :hash LIMIT 1");
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns a safe per-IP rate-limit check closure (same logic as login).
 */
function check_ip_rate_limit(PDO $pdo, string $event): void
{
    ensure_activity_log_table($pdo);
    $ipCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM `ACTIVITY_LOG`
         WHERE event = :ev AND ip_address = :ip
           AND created_at > UTC_TIMESTAMP() - INTERVAL :win SECOND"
    );
    $ipCheck->execute(['ev' => $event, 'ip' => client_ip(), 'win' => LIBROWSE_LOGIN_IP_WINDOW]);
    if ((int) $ipCheck->fetchColumn() >= LIBROWSE_LOGIN_IP_LIMIT) {
        header('Retry-After: ' . LIBROWSE_LOGIN_IP_WINDOW);
        Response::error('Too many requests from your network. Please wait '
            . intdiv(LIBROWSE_LOGIN_IP_WINDOW, 60) . ' minutes and try again.', 429);
    }
}

// ── Route ─────────────────────────────────────────────────────────────────

$action = strtolower((string) ($_GET['action'] ?? ''));

try {

    // ── LOGIN ────────────────────────────────────────────────────────────
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body       = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password   = (string) ($body['password'] ?? '');
        if ($identifier === '' || $password === '') {
            Response::error('Username/email and password are required.', 422);
        }

        /* ONE MESSAGE FOR EVERY FAILED SIGN-IN – prevents account enumeration */
        $genericFail = function (): void {
            Response::json([
                'error'  => 'Invalid username/email or password. For your security, an account is locked after '
                          . (LIBROWSE_LOGIN_MAX_ATTEMPTS + 1) . ' incorrect passwords in a row.',
                'locked' => false,
            ], 401);
        };

        /* Per-IP brute-force limit */
        check_ip_rate_limit($pdo, 'login_failed');

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, role, status, permission
             FROM `USER`
             WHERE deleted_at IS NULL AND (username = :username_identifier OR LOWER(email) = LOWER(:email_identifier))
             LIMIT 1'
        );
        $stmt->execute([
            'username_identifier' => $identifier,
            'email_identifier'    => $identifier,
        ]);
        $user = $stmt->fetch();

        $maxAttempts = LIBROWSE_LOGIN_MAX_ATTEMPTS;

        if (!$user) {
            // Spend same time as real hash check so timing doesn't leak account existence
            password_verify($password, '$2y$10$qJj5v/hVy4wAq/1N.vYu/uGq/hL4hsGrXybLqNLV73YQDhOkM4V/u');
            log_event('login_failed', null, ['identifier' => substr($identifier, 0, 100), 'reason' => 'unknown_account'], 'warning');
            $genericFail();
        }

        $hash  = (string) $user['password_hash'];
        $valid = $hash !== '' && password_verify($password, $hash);

        // Compatibility: upgrade broken placeholder hashes from the seed data
        if (!$valid && str_starts_with($hash, '$2b$') && strlen($hash) < 60 && $password === 'password') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id')
                ->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }
        if (!$valid && $hash !== '' && !str_starts_with($hash, '$') && hash_equals($hash, $password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id')
                ->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }

        $uid = (int) $user['user_id'];

        if (!$valid) {
            log_event('login_failed', $uid, ['reason' => 'wrong_password', 'status' => $user['status']], 'warning');
            if ($user['status'] !== 'Active') $genericFail();   // don't reveal lock/suspend status

            $pdo->prepare('INSERT INTO `LOGIN_ATTEMPTS` (user_id, attempted_at) VALUES (:uid, UTC_TIMESTAMP())')
                ->execute(['uid' => $uid]);
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid AND cleared_at IS NULL');
            $countStmt->execute(['uid' => $uid]);
            $failed = (int) $countStmt->fetchColumn();

            if ($failed > $maxAttempts) {
                $lock = $pdo->prepare("UPDATE `USER` SET status = 'Locked' WHERE user_id = :uid");
                $lock->execute(['uid' => $uid]);
                $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')
                    ->execute(['uid' => $uid]);
                revoke_all_sessions_for_user($uid);
                log_event('account_locked', $uid, ['failed_attempts' => $failed], 'critical');
            }
            $genericFail();
        }

        /* Correct password from here — only reveal real account state to the actual user */
        if ($user['status'] === 'Locked') {
            log_event('login_blocked_locked', $uid, [], 'warning');
            Response::json([
                'error'        => 'Your account is locked because of too many incorrect password attempts. Please contact an administrator to unlock it.',
                'locked'       => true,
                'username'     => (string) $user['username'],
                'max_attempts' => $maxAttempts,
            ], 423);
        }

        if ($user['status'] === 'Pending Verification') {
            log_event('login_blocked_unverified', $uid, [], 'warning');
            Response::json([
                'error'             => 'Please verify your email address before signing in. Check your inbox for the confirmation link.',
                'unverified'        => true,
                'email'             => (string) $user['email'],
                'resend_action'     => 'resend-verification',
            ], 403);
        }

        if ($user['status'] !== 'Active') {
            log_event('login_blocked_inactive', $uid, ['status' => $user['status']], 'warning');
            Response::error('This account is not active and cannot sign in.', 403);
        }

        $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')
            ->execute(['uid' => $uid]);

        $token = issue_auth_token($user);
        log_event('login_success', $uid, ['role' => $user['role']]);
        Response::json([
            'authenticated' => true,
            'token'         => $token,
            'expires_in'    => LIBROWSE_SESSION_TTL,
            'idle_timeout'  => LIBROWSE_IDLE_TIMEOUT,
            'user'          => public_user($user),
        ]);
    }

    // ── REGISTER ─────────────────────────────────────────────────────────
    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body     = auth_body();
        $username = trim((string) ($body['username'] ?? ''));
        $email    = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $emailPattern = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username))
            Response::error('Username must be 3-50 characters and contain only letters, numbers, and underscores.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match($emailPattern, $email))
            Response::error('Please enter a valid email address (e.g. you@example.com).', 422);

        $pwErrors = [];
        if (strlen($password) < 8)                     $pwErrors[] = 'at least 8 characters';
        if (!preg_match('/[A-Z]/', $password))          $pwErrors[] = 'an uppercase letter (A–Z)';
        if (!preg_match('/[a-z]/', $password))          $pwErrors[] = 'a lowercase letter (a–z)';
        if (!preg_match('/[0-9]/', $password))          $pwErrors[] = 'a number (0–9)';
        if (!preg_match('/[^A-Za-z0-9]/', $password))  $pwErrors[] = 'a special character (e.g. @, #, !, %)';
        if (strlen($password) > 72)
            Response::error('Password must be 72 characters or fewer.', 422);
        if ($pwErrors)
            Response::error('Password is too weak. It must include: ' . implode(', ', $pwErrors) . '.', 422);

        $check = $pdo->prepare('SELECT user_id FROM `USER` WHERE username = :username OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username' => $username, 'email' => $email]);
        if ($check->fetch()) Response::error('Username or email is already registered.', 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO `USER` (username, email, password_hash, role, status, permission)
             VALUES (:username, :email, :hash, 'Customer', 'Pending Verification', '{}')"
        );
        $insert->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);
        $newUserId = (int) $pdo->lastInsertId();

        // Store initial password in history
        $pdo->prepare('INSERT INTO `PASSWORD_HISTORY` (user_id, password_hash) VALUES (:uid, :hash)')
            ->execute(['uid' => $newUserId, 'hash' => $hash]);

        // Send verification email
        $rawToken = store_token($pdo, 'EMAIL_VERIFICATIONS', $newUserId, 'verification_id', 86400); // 24h
        $mailRes  = send_verification_email($email, $username, $rawToken);

        log_event('account_registered', $newUserId, ['email' => $email]);

        $responseData = [
            'registered'   => true,
            'verify_email' => true,
            'message'      => 'Account created! Please check your email to verify your account before signing in.',
            'email'        => $email,
        ];

        if ($mailRes['dev_mode'] ?? false) {
            $responseData['dev_mode']         = true;
            $responseData['dev_preview_link'] = $mailRes['dev_preview_link'] ?? null;
        }

        Response::json($responseData, 201);
    }

    // ── VERIFY EMAIL ─────────────────────────────────────────────────────
    if ($action === 'verify-email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body  = auth_body();
        $token = trim((string) ($body['token'] ?? ''));
        if ($token === '') Response::error('Verification token is required.', 422);

        $rec = validate_token($pdo, 'EMAIL_VERIFICATIONS', $token);
        if (!$rec)
            Response::error('This verification link is invalid. Please request a new one.', 400);
        if ($rec['used_at'] !== null)
            Response::error('This verification link has already been used.', 400);
        if (strtotime($rec['expires_at']) < time())
            Response::error('This verification link has expired. Please request a new verification email.', 400);

        $uid = (int) $rec['user_id'];

        // Mark token used
        $pdo->prepare("UPDATE `EMAIL_VERIFICATIONS` SET used_at = UTC_TIMESTAMP() WHERE verification_id = :id")
            ->execute(['id' => $rec['verification_id']]);

        // Activate the account
        $pdo->prepare("UPDATE `USER` SET status = 'Active' WHERE user_id = :uid AND status = 'Pending Verification'")
            ->execute(['uid' => $uid]);

        log_event('email_verified', $uid, [], 'info');
        Response::json(['verified' => true, 'message' => 'Your email has been verified. You can now sign in.']);
    }

    // ── RESEND VERIFICATION ───────────────────────────────────────────────
    if ($action === 'resend-verification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_ip_rate_limit($pdo, 'resend_verification');

        $body       = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? $body['email'] ?? ''));
        if ($identifier === '') Response::error('Email address or username is required.', 422);

        // Same safe reply whether or not account exists
        $safeReply = fn() => Response::json([
            'message' => 'If an unverified account matches that information, a verification email has been sent.',
        ]);

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, status FROM `USER`
             WHERE deleted_at IS NULL AND (username = :u OR LOWER(email) = LOWER(:e)) LIMIT 1'
        );
        $stmt->execute(['u' => $identifier, 'e' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'Pending Verification') {
            log_event('resend_verification', null, ['identifier' => substr($identifier, 0, 100)], 'info');
            $safeReply();
        }

        $uid      = (int) $user['user_id'];
        $rawToken = store_token($pdo, 'EMAIL_VERIFICATIONS', $uid, 'verification_id', 86400);
        $mailRes  = send_verification_email((string) $user['email'], (string) $user['username'], $rawToken);
        log_event('resend_verification', $uid, [], 'info');

        $resp = ['message' => 'If an unverified account matches that information, a verification email has been sent.'];
        if ($mailRes['dev_mode'] ?? false) {
            $resp['dev_preview_link'] = $mailRes['dev_preview_link'];
        }
        Response::json($resp);
    }

    // ── FORGOT PASSWORD ───────────────────────────────────────────────────
    if ($action === 'forgot-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_ip_rate_limit($pdo, 'forgot_password');

        $body       = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? $body['email'] ?? ''));
        if ($identifier === '') Response::error('Email address or username is required.', 422);

        $safeReply = fn() => Response::json([
            'message' => 'If an account exists with that email or username, a password reset link has been sent.',
        ]);

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, status FROM `USER`
             WHERE deleted_at IS NULL AND (username = :u OR LOWER(email) = LOWER(:e)) LIMIT 1'
        );
        $stmt->execute(['u' => $identifier, 'e' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || in_array($user['status'], ['Banned', 'Suspended'], true)) {
            log_event('forgot_password', null, ['identifier' => substr($identifier, 0, 100)], 'info');
            $safeReply();
        }

        $uid      = (int) $user['user_id'];
        $rawToken = store_token($pdo, 'PASSWORD_RESETS', $uid, 'reset_id', 3600); // 1 hour
        $mailRes  = send_password_reset_email((string) $user['email'], (string) $user['username'], $rawToken);
        log_event('forgot_password', $uid, [], 'info');

        $resp = ['message' => 'If an account exists with that email or username, a password reset link has been sent. Please check your inbox.'];
        if ($mailRes['dev_mode'] ?? false) {
            $resp['dev_preview_link'] = $mailRes['dev_preview_link'];
        }
        Response::json($resp);
    }

    // ── VERIFY RESET TOKEN (GET — called by reset-password page on load) ──
    if ($action === 'verify-reset-token' && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        $rawToken = trim((string) ($_GET['token'] ?? (auth_body()['token'] ?? '')));
        if ($rawToken === '') Response::error('Reset token is required.', 422);

        $rec = validate_token($pdo, 'PASSWORD_RESETS', $rawToken);
        if (!$rec || $rec['used_at'] !== null)
            Response::error('This password reset link is invalid or has already been used.', 400);
        if (strtotime($rec['expires_at']) < time())
            Response::error('This password reset link has expired. Please request a new one.', 400);

        $user = $pdo->prepare('SELECT username FROM `USER` WHERE user_id = :uid LIMIT 1');
        $user->execute(['uid' => $rec['user_id']]);
        $u = $user->fetch();

        Response::json(['valid' => true, 'username' => $u['username'] ?? '']);
    }

    // ── RESET PASSWORD ────────────────────────────────────────────────────
    if ($action === 'reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body     = auth_body();
        $rawToken = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($rawToken === '') Response::error('Reset token is required.', 422);

        $rec = validate_token($pdo, 'PASSWORD_RESETS', $rawToken);
        if (!$rec || $rec['used_at'] !== null)
            Response::error('This password reset link is invalid or has already been used.', 400);
        if (strtotime($rec['expires_at']) < time())
            Response::error('This password reset link has expired. Please request a new one.', 400);

        // Password strength
        $pwErrors = [];
        if (strlen($password) < 8)                     $pwErrors[] = 'at least 8 characters';
        if (!preg_match('/[A-Z]/', $password))          $pwErrors[] = 'an uppercase letter (A–Z)';
        if (!preg_match('/[a-z]/', $password))          $pwErrors[] = 'a lowercase letter (a–z)';
        if (!preg_match('/[0-9]/', $password))          $pwErrors[] = 'a number (0–9)';
        if (!preg_match('/[^A-Za-z0-9]/', $password))  $pwErrors[] = 'a special character (e.g. @, #, !, %)';
        if (strlen($password) > 72)
            Response::error('Password must be 72 characters or fewer.', 422);
        if ($pwErrors)
            Response::error('Password is too weak. It must include: ' . implode(', ', $pwErrors) . '.', 422);

        $uid = (int) $rec['user_id'];

        // Check password history (last 5)
        $histStmt = $pdo->prepare(
            'SELECT password_hash FROM `PASSWORD_HISTORY` WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5'
        );
        $histStmt->execute(['uid' => $uid]);
        foreach ($histStmt->fetchAll() as $h) {
            if (!empty($h['password_hash']) && password_verify($password, $h['password_hash'])) {
                Response::error('You cannot reuse a recent password. Please choose a different password.', 422);
            }
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // Mark token used
            $pdo->prepare("UPDATE `PASSWORD_RESETS` SET used_at = UTC_TIMESTAMP() WHERE reset_id = :id")
                ->execute(['id' => $rec['reset_id']]);

            // Fetch user to check status
            $userStmt = $pdo->prepare("SELECT user_id, status FROM `USER` WHERE user_id = :uid LIMIT 1");
            $userStmt->execute(['uid' => $uid]);
            $userRow = $userStmt->fetch();

            // Update password
            $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :uid')
                ->execute(['hash' => $newHash, 'uid' => $uid]);

            // If account was locked (e.g., brute-forced), auto-unlock it
            if ($userRow && $userRow['status'] === 'Locked') {
                $pdo->prepare("UPDATE `USER` SET status = 'Active' WHERE user_id = :uid")
                    ->execute(['uid' => $uid]);
                // Clear login attempts
                $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')
                    ->execute(['uid' => $uid]);
            }

            // Record in history
            $pdo->prepare('INSERT INTO `PASSWORD_HISTORY` (user_id, password_hash) VALUES (:uid, :hash)')
                ->execute(['uid' => $uid, 'hash' => $newHash]);

            // Sign out all active sessions (security: old password is no longer valid)
            revoke_all_sessions_for_user($uid);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        log_event('password_reset_done', $uid, [], 'warning');
        Response::json(['reset' => true, 'message' => 'Password reset successfully. You can now sign in with your new password.']);
    }

    // ── VALIDATE ─────────────────────────────────────────────────────────
    if ($action === 'validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_authenticated_user($pdo);
        Response::json([
            'authenticated' => true,
            'idle_timeout'  => LIBROWSE_IDLE_TIMEOUT,
            'user'          => public_user($user),
        ]);
    }

    // ── LOGOUT ────────────────────────────────────────────────────────────
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = bearer_token_from_request();
        $user  = $token ? current_authenticated_user($pdo) : null;
        revoke_auth_token($token);
        if ($user) log_event('logout', (int) $user['user_id']);
        Response::json(['authenticated' => false, 'message' => 'Session revoked.']);
    }

    // ── LOGOUT ALL DEVICES ────────────────────────────────────────────────
    if ($action === 'logout_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user  = require_authenticated_user($pdo);
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
