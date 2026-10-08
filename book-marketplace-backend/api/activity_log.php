<?php
/**
 * /api/activity_log.php — Admin only, read only.
 *
 *   GET ?limit=200&event=login_failed&severity=warning&user_id=5
 *       → newest log entries first (filters optional)
 *   GET ?summary=1
 *       → monitoring summary + alerts (unusual activity), used by the
 *         Admin "Activity Log" tab
 *
 * Logging = recording events. Monitoring = watching them and reacting.
 * The summary below is the "watching" part; the automatic reactions
 * (account lock, IP throttling, idle sign-out) live in auth.php and
 * security.php.
 *
 * Log rows can't be created, edited or deleted through the API.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('The activity log is read-only.', 405);
}
require_authenticated_user($pdo, ['Admin']);
ensure_activity_log_table($pdo);

try {
    if (isset($_GET['summary'])) {
        $count = function (string $where, array $params = []) use ($pdo): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `ACTIVITY_LOG` WHERE {$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        $summary = [
            'failed_logins_1h'      => $count("event = 'login_failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR"),
            'successful_logins_24h' => $count("event = 'login_success' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
            'accounts_locked_24h'   => $count("event = 'account_locked' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
            'permission_denied_24h' => $count("event = 'permission_denied' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
            'rate_limited_24h'      => $count("event = 'login_rate_limited' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
            'idle_signouts_24h'     => $count("event = 'session_idle_timeout' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
        ];

        // IPs with the most failed sign-ins in the last hour
        $top = $pdo->query(
            "SELECT ip_address, COUNT(*) AS failures, COUNT(DISTINCT COALESCE(user_id, 0)) AS accounts
             FROM `ACTIVITY_LOG`
             WHERE event = 'login_failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR
             GROUP BY ip_address ORDER BY failures DESC LIMIT 5"
        )->fetchAll();

        $alerts = [];
        if ($summary['failed_logins_1h'] >= 10) {
            $alerts[] = ['level' => 'critical', 'message' => "{$summary['failed_logins_1h']} failed sign-ins in the last hour — possible password-guessing attack."];
        }
        foreach ($top as $row) {
            if ((int) $row['failures'] >= 5 && (int) $row['accounts'] >= 3) {
                $alerts[] = ['level' => 'critical', 'message' => "IP {$row['ip_address']} failed to sign in to {$row['accounts']} different accounts in the last hour."];
            }
        }
        if ($summary['accounts_locked_24h'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$summary['accounts_locked_24h']} account(s) locked in the last 24 hours. Check Users for unlock requests."];
        }
        if ($summary['permission_denied_24h'] >= 5) {
            $alerts[] = ['level' => 'warning', 'message' => "{$summary['permission_denied_24h']} blocked attempts to use features without permission in the last 24 hours."];
        }

        Response::json(['summary' => $summary, 'top_failed_ips' => $top, 'alerts' => $alerts]);
    }

    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['event']) && preg_match('/^[a-z_]{1,50}$/', (string) $_GET['event'])) {
        $where[] = 'l.event = :event';
        $params['event'] = $_GET['event'];
    }
    if (!empty($_GET['severity']) && in_array($_GET['severity'], ['info', 'warning', 'critical'], true)) {
        $where[] = 'l.severity = :sev';
        $params['sev'] = $_GET['severity'];
    }
    if (!empty($_GET['user_id'])) {
        $where[] = 'l.user_id = :uid';
        $params['uid'] = (int) $_GET['user_id'];
    }
    $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

    $stmt = $pdo->prepare(
        'SELECT l.log_id, l.user_id, u.username, l.event, l.severity, l.details, l.ip_address, l.user_agent, l.created_at
         FROM `ACTIVITY_LOG` l LEFT JOIN `USER` u ON u.user_id = l.user_id
         WHERE ' . implode(' AND ', $where) . " ORDER BY l.log_id DESC LIMIT {$limit}"
    );
    $stmt->execute($params);
    Response::json($stmt->fetchAll());
} catch (PDOException $e) {
    database_error_response($e);
}
