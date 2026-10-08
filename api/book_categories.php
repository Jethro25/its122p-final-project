<?php
/**
 * /api/book_categories.php
 * GET (list/show), POST (create), PUT (update), DELETE
 */
require_once __DIR__ . '/../book-marketplace-backend/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $authUser = require_authenticated_user($pdo);
} else {
    $authUser = require_authenticated_user($pdo, ['Admin']);
}

/* The creator is the signed-in admin — never an ID typed into the request */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_body();
    $name = trim((string) ($body['category_name'] ?? ''));
    if ($name === '') Response::error('Category name is required.', 422);
    if (text_length($name) > 100) Response::error('Category name must be 100 characters or fewer.', 422);
    $body['category_name'] = $name;
    $body['created_by_admin_id'] = (int) $authUser['user_id'];
    $GLOBALS['librowse_body_override'] = $body;
}

$crud = new Crud(
    pdo: $pdo,
    table: 'BOOK_CATEGORIES',
    primaryKey: 'category_id',
    insertable: ['created_by_admin_id', 'category_name', 'description'],
    required: ['created_by_admin_id', 'category_name'],
);

dispatch_crud_request($crud, 'category_id');
