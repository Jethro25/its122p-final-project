<?php
/**
 * /api/user.php
 *
 * Who can do what:
 *   GET     Customers see only user_id, username and role of other users
 *           (enough to show seller names) plus their own full record.
 *           Staff/Admin see everyone. password_hash is never returned.
 *   POST    Admin only (new accounts normally come from auth.php?action=register).
 *   PUT     Admin: any field except password_hash.
 *           Staff: status of Customer accounts only, and never to or from
 *           'Locked' (unlocking is an Admin decision).
 *           Nobody can change their own role or deactivate themselves.
 *   DELETE  Admin only — soft delete (deleted_at), never your own account.
 */
require_once __DIR__ . '/../book-marketplace-backend/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);

$crud = new Crud(
    pdo: $pdo,
    table: 'USER',
    primaryKey: 'user_id',
    insertable: ['username', 'email', 'password_hash', 'role', 'status', 'permission'],
    required: ['username', 'email', 'password_hash'],
    enums: [
        'role'   => ['Customer', 'Staff', 'Admin'],
        'status' => ['Active', 'Suspended', 'Banned', 'Pending Verification', 'Locked'],
    ],
    updatable: ['username', 'email', 'role', 'status', 'permission'],
    hidden: ['password_hash'],
);

/* Customers only get public fields for other people */
function public_fields(array $row, array $authUser): array
{
    if ((int) $row['user_id'] === (int) $authUser['user_id']) return $row;
    return [
        'user_id'  => $row['user_id'],
        'username' => $row['username'],
        'role'     => $row['role'],
    ];
}

try {
    if ($method === 'GET') {
        if (!is_staff_or_admin($authUser)) {
            if (isset($_GET['id'])) {
                $row = $crud->show($_GET['id']);
                if (!$row) Response::error('User not found.', 404);
                Response::json(public_fields($row, $authUser));
            }
            $rows = $crud->index(['limit' => $_GET['limit'] ?? 1000, 'offset' => $_GET['offset'] ?? 0]);
            Response::json(array_map(fn($r) => public_fields($r, $authUser), $rows));
        }
        // Staff/Admin: normal list/show (password_hash is stripped by Crud)
    }

    if ($method === 'POST') {
        // Admin-created accounts: send a plain `password`; it is hashed here.
        require_authenticated_user($pdo, ['Admin']);
        $body = request_body();
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters.', 422);
        unset($body['password'], $body['password_hash']);
        $body['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        try {
            Response::json($crud->create($body), 201);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        require_authenticated_user($pdo, ['Staff', 'Admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $target = $crud->show($id);
        if (!$target) Response::error('User not found.', 404);
        $body = request_body();

        if (array_key_exists('password_hash', $body)) {
            Response::error('Passwords cannot be changed here.', 422);
        }
        if ($id === (int) $authUser['user_id']) {
            if (isset($body['role']) && $body['role'] !== $target['role']) {
                Response::error('You cannot change your own role.', 403);
            }
            if (isset($body['status']) && $body['status'] !== 'Active') {
                Response::error('You cannot deactivate your own account.', 403);
            }
        }

        if ($authUser['role'] === 'Staff') {
            if ($target['role'] !== 'Customer') {
                Response::error('Staff can only manage customer accounts.', 403);
            }
            $extra = array_diff(array_keys($body), ['status']);
            if ($extra) {
                Response::error('Staff can only change an account\'s status.', 403);
            }
            if (isset($body['status']) && ($body['status'] === 'Locked' || $target['status'] === 'Locked')) {
                Response::error('Only an administrator can lock or unlock an account.', 403);
            }
        }
    }

    if ($method === 'DELETE') {
        require_authenticated_user($pdo, ['Admin']);
        if ((int) ($_GET['id'] ?? 0) === (int) $authUser['user_id']) {
            Response::error('You cannot archive your own account.', 403);
        }
    }
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'user_id');
