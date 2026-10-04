<?php
/**
 * /api/unlock_request.php — public (no sign-in needed)
 * POST { "identifier": "liam_brown", "message": "optional note" }
 *
 * Lets a user whose account is Locked ask an administrator to unlock it.
 * The request lands in the admin "Reports & Forms" queue as a
 * Verification_Form with form_data.type = "unlock_request".
 *
 * The reply is always the same, whether or not the account exists or is
 * locked, so this endpoint can't be used to discover usernames.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$identifier = trim((string) ($body['identifier'] ?? ''));
$message = trim((string) ($body['message'] ?? ''));

if ($identifier === '' || strlen($identifier) > 255) {
    Response::error('Please enter your username or email.', 422);
}
if (strlen($message) > 1000) {
    Response::error('Please keep your message under 1000 characters.', 422);
}

ensure_column($pdo, 'USER', 'deleted_at');

$genericReply = [
    'ok'      => true,
    'message' => 'If this account is locked, your unlock request has been sent to the administrators. Try signing in again after they unlock it.',
];

$stmt = $pdo->prepare(
    'SELECT user_id, username, status FROM `USER`
     WHERE deleted_at IS NULL AND (username = :u OR LOWER(email) = LOWER(:e)) LIMIT 1'
);
$stmt->execute(['u' => $identifier, 'e' => $identifier]);
$user = $stmt->fetch();

if ($user && $user['status'] === 'Locked') {
    // Only one open request per account, so the queue can't be spammed
    $open = $pdo->prepare(
        "SELECT COUNT(*) FROM `REPORTS`
         WHERE submitted_by_id = :uid
           AND status IN ('Pending','Under_Review')
           AND form_data LIKE '%\"type\":\"unlock_request\"%'"
    );
    $open->execute(['uid' => (int) $user['user_id']]);

    if ((int) $open->fetchColumn() === 0) {
        $insert = $pdo->prepare(
            "INSERT INTO `REPORTS` (submitted_by_id, report_category, related_entity_type, form_data, status)
             VALUES (:uid, 'Verification_Form', 'User', :data, 'Pending')"
        );
        $insert->execute([
            'uid'  => (int) $user['user_id'],
            'data' => json_encode([
                'type'         => 'unlock_request',
                'username'     => $user['username'],
                'message'      => $message,
                'requested_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}

Response::json($genericReply);
