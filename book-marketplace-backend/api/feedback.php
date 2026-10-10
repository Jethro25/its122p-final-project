<?php
/**
 * /api/feedback.php — POST-only endpoint for post-transaction feedback.
 *
 * Creates a REPORTS row of type 'General_Feedback'.
 * Verifications the zip version skipped:
 *   • transaction_id must belong to the authenticated user
 *   • rating must be 1–5
 *   • comment is limited to 1000 characters
 *   • idempotency to prevent double submissions
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method   = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$me       = (int) $authUser['user_id'];

try {
    if ($method !== 'POST') Response::error('Method not allowed.', 405);

    idempotency_begin($pdo, $me, 'feedback');

    $body    = request_body();
    $comment = trim((string) ($body['comment'] ?? ''));
    $rating  = isset($body['rating']) ? (int) $body['rating'] : null;
    $txId    = isset($body['transaction_id']) ? (int) $body['transaction_id'] : null;

    if ($comment === '') Response::error('A comment is required.', 422);
    if (text_length($comment) > 1000) Response::error('Comment must be 1000 characters or fewer.', 422);
    if ($rating !== null && ($rating < 1 || $rating > 5)) Response::error('Rating must be between 1 and 5.', 422);

    $relatedType = 'None';
    $formData    = ['comment' => $comment];

    if ($txId !== null) {
        // Verify the transaction belongs to the user (as buyer or seller)
        $txStmt = $pdo->prepare(
            'SELECT t.transaction_id FROM `TRANSACTIONS` t
             LEFT JOIN `USER_BOOKS` r ON r.inventory_id = t.requested_inventory_id
             LEFT JOIN `USER_BOOKS` o ON o.inventory_id = t.offered_inventory_id
             WHERE t.transaction_id = :id AND t.deleted_at IS NULL
               AND (t.buyer_id = :me1 OR r.seller_id = :me2 OR o.seller_id = :me3)
             LIMIT 1'
        );
        $txStmt->execute(['id' => $txId, 'me1' => $me, 'me2' => $me, 'me3' => $me]);
        if (!$txStmt->fetch()) Response::error('Transaction not found in your orders.', 404);
        $relatedType          = 'Transaction';
        $formData['transaction_id'] = $txId;
    }

    if ($rating !== null) {
        $formData['rating'] = $rating;
    }

    $insert = $pdo->prepare(
        "INSERT INTO `REPORTS`
            (submitted_by_id, report_category, related_entity_type, form_data, status)
         VALUES (:uid, 'General_Feedback', :rtype, :fdata, 'Pending')"
    );
    $insert->execute([
        'uid'   => $me,
        'rtype' => $relatedType,
        'fdata' => json_encode($formData),
    ]);
    $reportId = (int) $pdo->lastInsertId();

    log_event('feedback_submitted', $me, ['report_id' => $reportId]);
    Response::json(['report_id' => $reportId, 'message' => 'Thank you for your feedback!'], 201);

} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}
