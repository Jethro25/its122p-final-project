<?php
declare(strict_types=1);
/**
 * /api/payments.php — PayPal order lifecycle for book purchases.
 *
 * GET  ?action=config
 *   → { mode, client_id, currency }
 *     (secret never leaves the server)
 *
 * POST ?action=create
 *   Body: { transaction_id }
 *   → { order_id, approve_url|null }
 *     Creates a PayPal order for the transaction.
 *     The amount is taken from the DATABASE, not the browser.
 *
 * POST ?action=capture
 *   Body: { order_id, transaction_id }
 *   → { payment_id, capture_id, status }
 *     Captures the order on PayPal and marks the transaction Paid.
 *     Idempotent: safe to call twice.
 *
 * POST ?action=refund
 *   Body: { transaction_id }
 *   Staff/Admin only. Issues a full PayPal refund for the capture.
 *   Called automatically by refund_request.php when a refund is approved.
 *
 * Tables created on first use:
 *   PAYMENTS — one row per capture/refund attempt
 *   TRANSACTIONS.payment_status column — Unpaid|Paid|Refunded|Not_required
 *   TRANSACTIONS.checkout_expires_at  — 30-min window for unpaid orders
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Schema migrations (run once, auto-upgrade older databases) ─────────────

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `PAYMENTS` (
        `payment_id`        INT UNSIGNED AUTO_INCREMENT NOT NULL,
        `transaction_id`    INT UNSIGNED NOT NULL,
        `provider`          ENUM('Simulated','PayPal_Sandbox') NOT NULL,
        `provider_order_id` VARCHAR(128) NOT NULL,
        `capture_id`        VARCHAR(128) NULL DEFAULT NULL,
        `refund_id`         VARCHAR(128) NULL DEFAULT NULL,
        `amount`            DECIMAL(10,2) NOT NULL,
        `currency`          CHAR(3) NOT NULL DEFAULT 'PHP',
        `status`            ENUM('Created','Captured','Failed','Refunded') NOT NULL DEFAULT 'Created',
        `payer_email`       VARCHAR(255) NULL DEFAULT NULL,
        `raw_response`      JSON NULL DEFAULT NULL,
        `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`payment_id`),
        UNIQUE KEY `uq_pay_order` (`provider_order_id`),
        INDEX `idx_pay_tx` (`transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
ensure_column($pdo, 'TRANSACTIONS', 'payment_status',
    "ENUM('Unpaid','Paid','Refunded','Not_required') NOT NULL DEFAULT 'Not_required'");
ensure_column($pdo, 'TRANSACTIONS', 'checkout_expires_at', 'DATETIME NULL DEFAULT NULL');

// ── Helpers ────────────────────────────────────────────────────────────────

function load_transaction(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM `TRANSACTIONS` WHERE transaction_id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function provider_name(): string
{
    return paypal_mode() === 'simulated' ? 'Simulated' : 'PayPal_Sandbox';
}

// ── Route ──────────────────────────────────────────────────────────────────

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$me       = (int) $authUser['user_id'];

try {

    // ── GET config ─────────────────────────────────────────────────────────
    if ($action === 'config' && $method === 'GET') {
        Response::json([
            'mode'      => paypal_mode(),
            'client_id' => trim(getenv('PAYPAL_CLIENT_ID') ?: ''),
            'currency'  => paypal_currency(),
        ]);
    }

    // ── POST create ────────────────────────────────────────────────────────
    if ($action === 'create' && $method === 'POST') {
        $body  = request_body();
        $txId  = (int) ($body['transaction_id'] ?? 0);
        $tx    = load_transaction($pdo, $txId);

        if (!$tx) Response::error('Transaction not found.', 404);
        if ((int) $tx['buyer_id'] !== $me) Response::error('You can only pay for your own orders.', 403);
        if ($tx['transaction_type'] !== 'Purchase') Response::error('Trades do not require payment.', 422);
        if (!in_array($tx['status'], ['Pending', 'Accepted'], true))
            Response::error('This order cannot be paid in its current status (' . $tx['status'] . ').', 422);

        // If already paid, return the existing payment row
        if ($tx['payment_status'] === 'Paid') {
            $existing = $pdo->prepare('SELECT * FROM `PAYMENTS` WHERE transaction_id = :id AND status = :s LIMIT 1');
            $existing->execute(['id' => $txId, 's' => 'Captured']);
            $row = $existing->fetch();
            Response::json(['already_paid' => true, 'payment_id' => $row ? (int) $row['payment_id'] : null]);
        }

        $amount   = (float) $tx['amount_paid'];
        $currency = paypal_currency();

        // Idempotency key based on transaction id so retries don't create a second order
        $idemKey  = 'librowse-create-' . $txId . '-' . date('YmdH');

        try {
            $result = paypal_create_order($amount, $currency, $txId, $idemKey);
        } catch (RuntimeException $e) {
            error_log('[librowse/payments] create order failed: ' . $e->getMessage());
            Response::error('Could not create a payment order. Please try again.', 502);
        }

        // Store the order in PAYMENTS
        $pdo->prepare(
            "INSERT INTO `PAYMENTS` (transaction_id, provider, provider_order_id, amount, currency, status)
             VALUES (:tx, :prov, :oid, :amt, :cur, 'Created')
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = UTC_TIMESTAMP()"
        )->execute([
            'tx'   => $txId,
            'prov' => provider_name(),
            'oid'  => $result['order_id'],
            'amt'  => $amount,
            'cur'  => $currency,
        ]);

        // Set a 30-minute checkout window (shorter than the 72-hour reservation)
        $pdo->prepare(
            "UPDATE `TRANSACTIONS` SET checkout_expires_at = UTC_TIMESTAMP() + INTERVAL 30 MINUTE
             WHERE transaction_id = :id AND (payment_status = 'Unpaid' OR payment_status = 'Not_required')"
        )->execute(['id' => $txId]);
        $pdo->prepare(
            "UPDATE `TRANSACTIONS` SET payment_status = 'Unpaid'
             WHERE transaction_id = :id AND payment_status IN ('Unpaid', 'Not_required')"
        )->execute(['id' => $txId]);

        log_event('payment_order_created', $me, [
            'transaction_id' => $txId,
            'order_id'       => $result['order_id'],
            'mode'           => paypal_mode(),
        ]);

        Response::json([
            'order_id'    => $result['order_id'],
            'approve_url' => $result['approve_url'],
            'mode'        => paypal_mode(),
            'amount'      => $amount,
            'currency'    => $currency,
        ]);
    }

    // ── POST capture ───────────────────────────────────────────────────────
    if ($action === 'capture' && $method === 'POST') {
        $body    = request_body();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $txId    = (int) ($body['transaction_id'] ?? 0);
        if ($orderId === '') Response::error('order_id is required.', 422);
        if (!$txId)          Response::error('transaction_id is required.', 422);

        $tx = load_transaction($pdo, $txId);
        if (!$tx) Response::error('Transaction not found.', 404);
        if ((int) $tx['buyer_id'] !== $me) Response::error('You can only capture your own payment.', 403);

        // Idempotent: already captured
        if ($tx['payment_status'] === 'Paid') {
            $pay = $pdo->prepare('SELECT * FROM `PAYMENTS` WHERE transaction_id = :id AND status = :s LIMIT 1');
            $pay->execute(['id' => $txId, 's' => 'Captured']);
            $row = $pay->fetch();
            Response::json(['already_paid' => true, 'payment_id' => $row ? (int) $row['payment_id'] : null, 'status' => 'Paid']);
        }

        // Check checkout window hasn't expired
        if (!empty($tx['checkout_expires_at']) && strtotime($tx['checkout_expires_at']) < time()) {
            Response::error('The payment window for this order has expired. The order has been cancelled.', 410);
        }

        // Verify the PAYMENTS row exists for this order_id
        $payRow = $pdo->prepare('SELECT * FROM `PAYMENTS` WHERE provider_order_id = :oid AND transaction_id = :tx LIMIT 1');
        $payRow->execute(['oid' => $orderId, 'tx' => $txId]);
        $pay = $payRow->fetch();
        if (!$pay) Response::error('Payment order not found. Please start the checkout again.', 404);

        $idemKey = 'librowse-capture-' . $txId . '-' . $orderId;

        try {
            $result = paypal_capture_order($orderId, $idemKey);
        } catch (RuntimeException $e) {
            // Record failure
            $pdo->prepare("UPDATE `PAYMENTS` SET status = 'Failed', updated_at = UTC_TIMESTAMP() WHERE payment_id = :id")
                ->execute(['id' => $pay['payment_id']]);
            log_event('payment_capture_failed', $me, ['transaction_id' => $txId, 'error' => $e->getMessage()], 'critical');
            Response::error('Payment capture failed: ' . $e->getMessage(), 502);
        }

        // Verify amount matches the database record (prevent price tampering)
        if ($result['amount'] !== null) {
            $dbAmount    = round((float) $pay['amount'], 2);
            $paidAmount  = round($result['amount'], 2);
            if (abs($dbAmount - $paidAmount) > 0.01) {
                log_event('payment_amount_mismatch', $me, [
                    'transaction_id' => $txId,
                    'expected'       => $dbAmount,
                    'received'       => $paidAmount,
                ], 'critical');
                Response::error('Payment amount does not match the order. Please contact support.', 422);
            }
        }

        $pdo->beginTransaction();
        try {
            // Update PAYMENTS row
            $pdo->prepare(
                "UPDATE `PAYMENTS`
                 SET status = 'Captured', capture_id = :cap, payer_email = :email,
                     raw_response = :raw, updated_at = UTC_TIMESTAMP()
                 WHERE payment_id = :id"
            )->execute([
                'cap'   => $result['capture_id'],
                'email' => $result['payer_email'],
                'raw'   => json_encode($result['raw']),
                'id'    => (int) $pay['payment_id'],
            ]);

            // Mark transaction Paid
            $pdo->prepare(
                "UPDATE `TRANSACTIONS` SET payment_status = 'Paid', checkout_expires_at = NULL
                 WHERE transaction_id = :id"
            )->execute(['id' => $txId]);

            // Record in financial audit log
            $pdo->prepare(
                "INSERT INTO `SYSTEM_RECORDS` (record_type, details, created_by_user_id)
                 VALUES ('Financial_Transaction_Record', :d, :uid)"
            )->execute([
                'd'   => json_encode([
                    'event'          => 'payment_captured',
                    'transaction_id' => $txId,
                    'payment_id'     => (int) $pay['payment_id'],
                    'capture_id'     => $result['capture_id'],
                    'amount'         => $pay['amount'],
                    'currency'       => $pay['currency'],
                    'provider'       => provider_name(),
                    'payer_email'    => $result['payer_email'],
                ]),
                'uid' => $me,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        log_event('payment_captured', $me, [
            'transaction_id' => $txId,
            'payment_id'     => (int) $pay['payment_id'],
            'capture_id'     => $result['capture_id'],
        ]);

        Response::json([
            'paid'       => true,
            'payment_id' => (int) $pay['payment_id'],
            'capture_id' => $result['capture_id'],
            'status'     => 'Paid',
        ]);
    }

    // ── POST refund ────────────────────────────────────────────────────────
    if ($action === 'refund' && $method === 'POST') {
        require_authenticated_user($pdo, ['Staff', 'Admin']);

        $body = request_body();
        $txId = (int) ($body['transaction_id'] ?? 0);
        $tx   = load_transaction($pdo, $txId);
        if (!$tx) Response::error('Transaction not found.', 404);
        if ($tx['payment_status'] !== 'Paid') Response::error('This order has not been paid, so there is nothing to refund.', 422);

        // Find the Captured payment row
        $payStmt = $pdo->prepare('SELECT * FROM `PAYMENTS` WHERE transaction_id = :id AND status = :s LIMIT 1');
        $payStmt->execute(['id' => $txId, 's' => 'Captured']);
        $pay = $payStmt->fetch();
        if (!$pay) Response::error('No captured payment found for this transaction.', 404);
        if (empty($pay['capture_id'])) Response::error('Capture ID is missing — cannot refund.', 422);

        $idemKey = 'librowse-refund-' . $txId . '-' . $pay['capture_id'];

        try {
            $result = paypal_refund_capture(
                (string) $pay['capture_id'],
                (float) $pay['amount'],
                (string) $pay['currency'],
                $idemKey
            );
        } catch (RuntimeException $e) {
            log_event('payment_refund_failed', $me, ['transaction_id' => $txId, 'error' => $e->getMessage()], 'critical');
            Response::error('PayPal refund failed: ' . $e->getMessage(), 502);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE `PAYMENTS`
                 SET status = 'Refunded', refund_id = :rid, raw_response = :raw, updated_at = UTC_TIMESTAMP()
                 WHERE payment_id = :id"
            )->execute([
                'rid' => $result['refund_id'],
                'raw' => json_encode($result['raw']),
                'id'  => (int) $pay['payment_id'],
            ]);
            $pdo->prepare(
                "UPDATE `TRANSACTIONS` SET payment_status = 'Refunded' WHERE transaction_id = :id"
            )->execute(['id' => $txId]);
            $pdo->prepare(
                "INSERT INTO `SYSTEM_RECORDS` (record_type, details, created_by_user_id)
                 VALUES ('Financial_Transaction_Record', :d, :uid)"
            )->execute([
                'd'   => json_encode([
                    'event'          => 'payment_refunded',
                    'transaction_id' => $txId,
                    'payment_id'     => (int) $pay['payment_id'],
                    'refund_id'      => $result['refund_id'],
                    'amount'         => $pay['amount'],
                    'currency'       => $pay['currency'],
                    'provider'       => provider_name(),
                ]),
                'uid' => $me,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        log_event('payment_refunded', $me, ['transaction_id' => $txId, 'refund_id' => $result['refund_id']], 'warning');
        Response::json([
            'refunded'  => true,
            'refund_id' => $result['refund_id'],
            'status'    => 'Refunded',
        ]);
    }

    Response::error('Unknown payment action.', 404);

} catch (PDOException $e) {
    database_error_response($e);
} catch (Throwable $e) {
    error_log('[librowse/payments] ' . $e->getMessage());
    Response::error('Payment service error. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
}
