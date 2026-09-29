<?php
/**
 * /api/system_records.php
 * GET (list/show), POST (create), PUT (update), DELETE
 */
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_authenticated_user($pdo);
} else {
    require_authenticated_user($pdo, ['Admin']);
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
