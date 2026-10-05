<?php
/**
 * /api/reports.php — reports, forms, and unlock requests.
 *
 *   GET     Customers: reports they submitted. Staff/Admin: all.
 *   POST    Anyone signed in. submitted_by_id and status are set by the
 *           server; review fields can't be filled in by the submitter.
 *   PUT     Staff/Admin: status and resolution notes. The reviewer and the
 *           resolved time are recorded automatically.
 *   DELETE  Admin only (soft delete).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$isCustomer = !is_staff_or_admin($authUser);
$me = (int) $authUser['user_id'];

const REPORT_CLOSED = ['Approved', 'Rejected', 'Resolved', 'Dismissed'];

$crud = new Crud(
    pdo: $pdo,
    table: 'REPORTS',
    primaryKey: 'report_id',
    insertable: [
        'submitted_by_id', 'reviewed_by_id', 'report_category', 'related_entity_type',
        'form_data', 'status', 'resolution_notes', 'resolved_at',
    ],
    required: ['submitted_by_id', 'report_category', 'related_entity_type'],
    enums: [
        'report_category' => [
            'Verification_Form', 'Seller_Application', 'User_Violation',
            'Listing_Dispute', 'General_Feedback',
        ],
        'related_entity_type' => ['User', 'Book_Listing', 'Transaction', 'None'],
        'status' => ['Pending', 'Under_Review', 'Approved', 'Rejected', 'Resolved', 'Dismissed'],
    ],
    softDeleteColumn: 'deleted_at',
);

try {
    if ($method === 'GET' && $isCustomer) {
        if (isset($_GET['id'])) {
            $row = $crud->show($_GET['id']);
            if (!$row || (int) $row['submitted_by_id'] !== $me) Response::error('Report not found.', 404);
            Response::json($row);
        }
        Response::json($crud->index(['submitted_by_id' => $me, 'limit' => 1000]));
    }

    if ($method === 'POST') {
        $body = request_body();
        $formData = $body['form_data'] ?? null;
        if (is_array($formData)) $formData = json_encode($formData, JSON_UNESCAPED_UNICODE);
        $formData = $formData === null ? null : (string) $formData;
        if ($formData !== null && strlen($formData) > 5000) {
            Response::error('Please keep the report under 5000 characters.', 422);
        }
        Response::json($crud->create([
            'submitted_by_id'     => $me,                       // never trust the browser for this
            'report_category'     => $body['report_category'] ?? null,
            'related_entity_type' => $body['related_entity_type'] ?? 'None',
            'form_data'           => $formData,
            'status'              => 'Pending',
        ]), 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        require_authenticated_user($pdo, ['Staff', 'Admin']);
        $id = (int) ($_GET['id'] ?? 0);
        if (!$crud->show($id)) Response::error('Report not found.', 404);
        $body = request_body();
        $update = ['reviewed_by_id' => $me];
        if (array_key_exists('status', $body)) {
            $update['status'] = $body['status'];
            $update['resolved_at'] = in_array($body['status'], REPORT_CLOSED, true) ? now_sql() : null;
        }
        if (array_key_exists('resolution_notes', $body)) {
            $notes = trim((string) $body['resolution_notes']);
            if (text_length($notes) > 2000) Response::error('Please keep the notes under 2000 characters.', 422);
            $update['resolution_notes'] = $notes === '' ? null : $notes;
        }
        Response::json($crud->update($id, $update));
    }

    if ($method === 'DELETE') {
        require_authenticated_user($pdo, ['Admin']);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'report_id');
