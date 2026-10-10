<?php
declare(strict_types=1);

/**
 * Loads a .env file from the backend directory into getenv() / $_ENV.
 * Call this before anything that reads environment variables.
 *
 * Only runs once (static guard).  Values that are already set in the
 * real environment are NOT overwritten, so Vercel/system env vars win.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="quoted value"
 *   KEY='quoted value'
 *   # comment lines are ignored
 *   blank lines are ignored
 */
function load_dot_env(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $file = __DIR__ . '/../.env';
    if (!is_file($file) || !is_readable($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;

        [$key, $rawValue] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') continue;

        // Strip inline comments after an unquoted value
        $rawValue = trim($rawValue);
        if (str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"') && strlen($rawValue) >= 2) {
            $value = substr($rawValue, 1, -1);
        } elseif (str_starts_with($rawValue, "'") && str_ends_with($rawValue, "'") && strlen($rawValue) >= 2) {
            $value = substr($rawValue, 1, -1);
        } else {
            // Remove trailing inline comment (# preceded by space)
            $value = preg_replace('/\s+#.*$/', '', $rawValue) ?? $rawValue;
            $value = trim($value);
        }

        // Don't overwrite values that are already set by the host environment
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

load_dot_env();
