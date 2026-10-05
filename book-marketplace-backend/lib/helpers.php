<?php
/**
 * Small shared helpers used by the API endpoints.
 */

/** The decoded JSON request body (read once, cached). Rejects non-objects. */
function request_body(): array
{
    static $body = null;
    if ($body !== null) return $body;
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $body = [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        Response::error('Request body must be a JSON object.', 400);
    }
    return $body = $decoded;
}

/** Set APP_DEBUG=1 in the environment to include database error details in replies. */
function api_debug(): bool
{
    return getenv('APP_DEBUG') === '1';
}

/** Turns a database exception into a safe JSON error (details only in debug mode). */
function database_error_response(PDOException $e): void
{
    $code = (int) ($e->errorInfo[1] ?? 0);
    $extra = api_debug() ? ['details' => $e->getMessage()] : [];
    error_log('[librowse] ' . $e->getMessage());
    if ($code === 1062) {
        Response::error('A record with these values already exists.', 409, $extra);
    }
    if (in_array($code, [1451, 1452], true)) {
        Response::error('This change refers to a record that does not exist or is still in use.', 409, $extra);
    }
    Response::error('Something went wrong while saving. Please try again.', 500, $extra);
}

function is_staff_or_admin(array $user): bool
{
    return in_array($user['role'], ['Staff', 'Admin'], true);
}

/** Current time in the same format MySQL DATETIME uses. */
function now_sql(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Character count that works even if the mbstring extension is missing. */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}
