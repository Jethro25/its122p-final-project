<?php
/**
 * /api/system_records.php
 * GET (list/show), POST (create), PUT (update), DELETE
 */
require_once __DIR__ . '/../book-marketplace-backend/lib/bootstrap.php';

/* The audit log is for Staff and Admin only; only Admin can add or archive records */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $authUser = require_authenticated_user($pdo, ['Staff', 'Admin']);
} else {
    $authUser = require_authenticated_user($pdo, ['Admin']);
}

/* Audit records are written by the signed-in admin; the browser can't
   claim to be someone else. Audit records can't be edited afterwards. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_body();
    $body['admin_id'] = (int) $authUser['user_id'];
    $GLOBALS['librowse_body_override'] = $body;
}
if (in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
    Response::error('Audit records cannot be edited.', 405);
}

$crud = new Crud(
    pdo: $pdo,
    table: 'SYSTEM_RECORDS',
    primaryKey: 'record_id',
    insertable: ['admin_id', 'record_type', 'details'],
    required: ['admin_id', 'record_type'],
    enums: [
        'record_type' => ['Audit_Log', 'Financial_Transaction_Record'],
    ],
);

dispatch_crud_request($crud, 'record_id');
