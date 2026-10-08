<?php
/**
 * App settings in one place.
 *
 * Values that change between environments (or that an administrator may
 * want to tune) are read from environment variables, with safe defaults.
 * Nothing here needs a code change to adjust: set the variable in Vercel
 * (Project → Settings → Environment Variables) or in your local shell.
 *
 *   SESSION_IDLE_MINUTES      sign out after this much inactivity      (30)
 *   SESSION_MAX_HOURS         sign in again after this long, always   (8)
 *   LOGIN_MAX_ATTEMPTS        wrong passwords allowed before lock      (3)
 *   LOGIN_IP_LIMIT            failed sign-ins per IP per window        (20)
 *   LOGIN_IP_WINDOW_MINUTES   window for the IP limit                  (15)
 *   RESERVATION_HOURS         a Pending request holds a book this long (72)
 */
function env_int(string $key, int $default, int $min = 1): int
{
    $value = getenv($key);
    if ($value === false || $value === '' || !is_numeric($value)) return $default;
    return max($min, (int) $value);
}

define('LIBROWSE_IDLE_TIMEOUT', env_int('SESSION_IDLE_MINUTES', 30) * 60);
define('LIBROWSE_SESSION_TTL', env_int('SESSION_MAX_HOURS', 8) * 3600);
define('LIBROWSE_LOGIN_MAX_ATTEMPTS', env_int('LOGIN_MAX_ATTEMPTS', 3));
define('LIBROWSE_LOGIN_IP_LIMIT', env_int('LOGIN_IP_LIMIT', 20));
define('LIBROWSE_LOGIN_IP_WINDOW', env_int('LOGIN_IP_WINDOW_MINUTES', 15) * 60);
define('LIBROWSE_RESERVATION_HOURS', env_int('RESERVATION_HOURS', 72));
