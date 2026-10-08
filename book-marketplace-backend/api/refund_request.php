<?php
/**
 * /api/refund_request.php
 *
 *   GET     Customers: their own refund requests. Staff/Admin: all.
 *   POST    Customers only, for one of their own COMPLETED purchases that
 *           doesn't already have an open or approved refund. The server sets
 *           customer_id and status itself.
 *   PUT     Staff/Admin: decide a Pending request (Approved or Rejected).
 *           The reviewer is recorded automatically. Decisions are final.
 *   DELETE  Admin only (soft delete).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$isCustomer = !is_staff_or_admin($authUser);
$me = (int) $authUser['user_id'];

$crud = new Crud(
    pdo: $pdo,
    table: 'REFUND_REQUEST',
    primaryKey: 'refund_id',
    insertable: ['transaction_id', 'customer_id', 'processed_by_staff_id', 'reason', 'status'],
    required: ['transaction_id', 'customer_id', 'reason'],
    enums: ['status' => ['Pending', 'Approved', 'Rejected']],
    softDeleteColumn: 'deleted_at',
);

try {
    if ($method === 'GET' && $isCustomer) {
        if (isset($_GET['id'])) {
            $row = $crud->show($_GET['id']);
            if (!$row || (int) $row['customer_id'] !== $me) Response::error('Refund request not found.', 404);
            Response::json($row);
        }
        Response::json($crud->index(['customer_id' => $me, 'limit' => 1000]));
    }

    if ($method === 'POST') {
        if (!$isCustomer) Response::error('Refunds are requested by customers.', 403);
        idempotency_begin($pdo, $me, 'refund_request');
        $body = request_body();
        $txId = (int) ($body['transaction_id'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));
        if (text_length($reason) < 10) Response::error('Please describe the problem in at least 10 characters.', 422);
        if (text_length($reason) > 1000) Response::error('Please keep the reason under 1000 characters.', 422);

        $stmt = $pdo->prepare('SELECT * FROM `TRANSACTIONS` WHERE transaction_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $txId]);
        $tx = $stmt->fetch();
        if (!$tx || (int) $tx['buyer_id'] !== $me) Response::error('That transaction was not found in your purchases.', 404);
        if ($tx['transaction_type'] !== 'Purchase') Response::error('Refunds are only available for purchases, not trades.', 422);
        if ($tx['status'] !== 'Completed') Response::error('You can request a refund once the purchase is completed.', 422);

        $dup = $pdo->prepare("SELECT COUNT(*) FROM `REFUND_REQUEST` WHERE transaction_id = :id AND status IN ('Pending','Approved') AND deleted_at IS NULL");
        $dup->execute(['id' => $txId]);
        if ((int) $dup->fetchColumn() > 0) Response::error('A refund for this purchase is already open or approved.', 409);

        $created = $crud->create([
            'transaction_id' => $txId,
            'customer_id'    => $me,
            'reason'         => $reason,
            'status'         => 'Pending',
        ]);
        log_event('refund_requested', $me, ['refund_id' => (int) $created['refund_id'], 'transaction_id' => $txId]);
        Response::json($created, 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        require_authenticated_user($pdo, ['Staff', 'Admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $row = $crud->show($id);
        if (!$row) Response::error('Refund request not found.', 404);
        $to = (string) (request_body()['status'] ?? '');
        if ($to === $row['status']) Response::json($row);
        if ($row['status'] !== 'Pending') Response::error('This refund has already been decided.', 422);
        if (!in_array($to, ['Approved', 'Rejected'], true)) Response::error('Choose Approved or Rejected.', 422);
        // Decide it only if it is still Pending — two staff clicking at once can't both decide it
        $upd = $pdo->prepare("UPDATE `REFUND_REQUEST` SET status = :to, processed_by_staff_id = :me
                              WHERE refund_id = :id AND status = 'Pending' AND deleted_at IS NULL");
        $upd->execute(['to' => $to, 'me' => $me, 'id' => $id]);
        if ($upd->rowCount() !== 1) {
            Response::error('This refund was just decided by someone else. Refresh to see the latest status.', 409, ['current' => $crud->show($id)]);
        }
        log_event('refund_decided', $me, ['refund_id' => $id, 'decision' => $to], $to === 'Approved' ? 'warning' : 'info');
        Response::json($crud->show($id));
    }

    if ($method === 'DELETE') {
        require_authenticated_user($pdo, ['Admin']);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'refund_id');
