<?php
/**
 * Activity / security log.
 *
 * Records meaningful security and business events, not every click:
 * sign-ins (successful and failed), lockouts, sign-outs, expired sessions,
 * permission denials, role/status changes, purchase/trade status changes,
 * and refund decisions.
 *
 * Every row has WHO (user_id), WHAT (event + details), WHEN (created_at,
 * UTC) and WHERE FROM (IP address + browser). Passwords, session tokens and
 * cover photos are never written here.
 *
 * Admins read it in Admin → Activity Log, which also flags unusual activity
 * (monitoring), e.g. many failed sign-ins in the last hour.
 */

/** The caller's IP. On Vercel the platform sets x-vercel-forwarded-for (users can't fake it). */
function client_ip(): string
{
    if (getenv('VERCEL')) {
        foreach (['HTTP_X_VERCEL_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function ensure_activity_log_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ACTIVITY_LOG` (
            `log_id`     BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            `user_id`    INT UNSIGNED NULL,
            `event`      VARCHAR(50) NOT NULL,
            `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            `details`    TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`log_id`),
            INDEX `idx_al_event_time` (`event`, `created_at`),
            INDEX `idx_al_ip_time` (`ip_address`, `created_at`),
            INDEX `idx_al_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('[librowse] activity log table: ' . $e->getMessage());
    }
}

/**
 * Writes one event. Logging must never break the request it describes,
 * so any database error here is swallowed (and sent to the server log).
 */
function log_event(string $event, ?int $userId = null, array $details = [], string $severity = 'info'): void
{
    global $pdo;
    if (!$pdo) return;
    ensure_activity_log_table($pdo);

    // Defensive: never store secrets even if a caller passes them by mistake
    foreach (['password', 'password_hash', 'token', 'cover_image'] as $secret) unset($details[$secret]);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `ACTIVITY_LOG` (user_id, event, severity, details, ip_address, user_agent, created_at)
             VALUES (:uid, :event, :sev, :details, :ip, :ua, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uid'     => $userId,
            'event'   => substr($event, 0, 50),
            'sev'     => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip'      => client_ip(),
            'ua'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (PDOException $e) {
        error_log('[librowse] could not write activity log: ' . $e->getMessage());
    }
}
