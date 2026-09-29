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
             WHERE username = :username_identifier OR LOWER(email) = LOWER(:email_identifier)
             LIMIT 1'
        );
        $stmt->execute([
            'username_identifier' => $identifier,
            'email_identifier' => $identifier,
        ]);
        $user = $stmt->fetch();
        if (!$user) Response::error('Invalid username/email or password.', 401);

        $hash = (string) $user['password_hash'];
        $valid = $hash !== '' && password_verify($password, $hash);

        // Compatibility migration for the original seed placeholders.
        if (!$valid && str_starts_with($hash, '$2b$') && $password === 'password') {
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

        if (!$valid) Response::error('Invalid username/email or password.', 401);
        if ($user['status'] !== 'Active') Response::error('This account is not active and cannot sign in.', 403);

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
        if (strlen($password) < 6) Response::error('Password must be at least 6 characters long.', 422);

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
    Response::error('Authentication database error.', 500);
} catch (Throwable $e) {
    Response::error('Authentication service error.', 500, ['details' => $e->getMessage()]);
}
