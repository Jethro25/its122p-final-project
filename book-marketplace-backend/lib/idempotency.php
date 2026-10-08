<?php
/**
 * Duplicate-request protection (idempotency).
 *
 * The browser sends an `Idempotency-Key` header with every "create"
 * request (buy, trade, list a book, refund, report). The key stays the same
 * when the SAME request is sent again — a double click, a retry after the
 * connection dropped, or a reply that never arrived.
 *
 *   First time a key is seen  → the request runs normally and its reply is saved.
 *   Same key while still running → 409 "already being processed" (no second record).
 *   Same key after it finished  → the saved reply is returned again
 *                                 (header Idempotent-Replayed: true); nothing new is created.
 *
 * Server errors (5xx) are not saved, so the user can safely try again.
 * Keys are per user, so one user's key can never replay another user's reply.
 */
const IDEMPOTENCY_STALE_SECONDS = 60;   // a "processing" row older than this was a crashed request

function ensure_idempotency_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `IDEMPOTENCY_KEYS` (
            `user_id`       INT UNSIGNED NOT NULL,
            `idem_key`      VARCHAR(100) NOT NULL,
            `endpoint`      VARCHAR(100) NOT NULL,
            `status`        ENUM('processing','done') NOT NULL DEFAULT 'processing',
            `response_code` SMALLINT NULL,
            `response_body` MEDIUMTEXT NULL,
            `created_at`    DATETIME NOT NULL,
            PRIMARY KEY (`user_id`, `idem_key`),
            INDEX `idx_ik_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('[librowse] idempotency table: ' . $e->getMessage());
    }
}

/**
 * Call at the start of a create (POST) handler, after authentication.
 * Does nothing when the request has no Idempotency-Key header.
 */
function idempotency_begin(PDO $pdo, int $userId, string $endpoint): void
{
    $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($key === '') return;
    if (!preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
        Response::error('Invalid Idempotency-Key header.', 400);
    }
    ensure_idempotency_table($pdo);

    try {
        // Old keys are no longer needed
        $pdo->exec("DELETE FROM `IDEMPOTENCY_KEYS` WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");

        $claim = $pdo->prepare(
            "INSERT INTO `IDEMPOTENCY_KEYS` (user_id, idem_key, endpoint, status, created_at)
             VALUES (:uid, :k, :ep, 'processing', UTC_TIMESTAMP())"
        );
        $claim->execute(['uid' => $userId, 'k' => $key, 'ep' => $endpoint]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {        // not a duplicate key → skip protection, keep working
            error_log('[librowse] idempotency: ' . $e->getMessage());
            return;
        }
        // We have seen this key before
        $find = $pdo->prepare(
            'SELECT status, response_code, response_body, endpoint,
                    TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age
             FROM `IDEMPOTENCY_KEYS` WHERE user_id = :uid AND idem_key = :k'
        );
        $find->execute(['uid' => $userId, 'k' => $key]);
        $row = $find->fetch();
        if ($row && $row['endpoint'] === $endpoint && $row['status'] === 'done') {
            header('Idempotent-Replayed: true');
            http_response_code((int) $row['response_code']);
            header('Content-Type: application/json');
            echo $row['response_body'];
            exit;
        }
        if ($row && $row['status'] === 'processing' && (int) $row['age'] < IDEMPOTENCY_STALE_SECONDS) {
            Response::error('This request is already being processed. Please wait a moment.', 409, ['duplicate' => true]);
        }
        // A crashed earlier attempt: take the key over and run again
        $pdo->prepare("UPDATE `IDEMPOTENCY_KEYS` SET status = 'processing', endpoint = :ep, created_at = UTC_TIMESTAMP()
                       WHERE user_id = :uid AND idem_key = :k")
            ->execute(['uid' => $userId, 'k' => $key, 'ep' => $endpoint]);
    }

    // Remember the reply when the handler sends it
    Response::$beforeSend = function (int $code, string $body) use ($pdo, $userId, $key): void {
        try {
            // Reply sent while a database transaction is still open: that work is not
            // committed, so don't remember the reply (the stale "processing" row expires).
            if ($pdo->inTransaction()) return;
            if ($code >= 500) {
                $pdo->prepare('DELETE FROM `IDEMPOTENCY_KEYS` WHERE user_id = :uid AND idem_key = :k')
                    ->execute(['uid' => $userId, 'k' => $key]);
                return;
            }
            $pdo->prepare("UPDATE `IDEMPOTENCY_KEYS` SET status = 'done', response_code = :c, response_body = :b
                           WHERE user_id = :uid AND idem_key = :k")
                ->execute(['c' => $code, 'b' => $body, 'uid' => $userId, 'k' => $key]);
        } catch (PDOException $e) {
            error_log('[librowse] idempotency save: ' . $e->getMessage());
        }
    };
}
