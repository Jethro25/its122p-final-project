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
require_once __DIR__ . '/../lib/bootstrap.php';

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

/* Same rules as self-registration, enforced here too (admins use this
   endpoint, and a request can be sent without the admin page). */
function validate_user_fields(array $body): void
{
    if (array_key_exists('username', $body) && !preg_match('/^[a-zA-Z0-9_]{3,50}$/', (string) $body['username'])) {
        Response::error('Username must be 3-50 characters and contain only letters, numbers, and underscores.', 422, ['field' => 'username']);
    }
    if (array_key_exists('email', $body) && !filter_var((string) $body['email'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Please provide a valid email address.', 422, ['field' => 'email']);
    }
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
        validate_user_fields($body);
        $body['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        try {
            $newUser = $crud->create($body);
            log_event('user_created_by_admin', (int) $authUser['user_id'], ['new_user_id' => (int) $newUser['user_id'], 'role' => $newUser['role'] ?? null]);
            Response::json($newUser, 201);
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
        validate_user_fields($body);
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

        try {
            $updated = $crud->update($id, $body);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        $changes = [];
        foreach (['role', 'status', 'username', 'email'] as $f) {
            if (array_key_exists($f, $body) && (string) $body[$f] !== (string) $target[$f]) {
                $changes[$f] = ['from' => $target[$f], 'to' => $body[$f]];
            }
        }
        /* New role or no longer Active → end that user's sessions right now,
           so the new permissions apply immediately on every device. (Each
           request also re-checks the role on the server.) */
        $ended = 0;
        if (isset($changes['role']) || (isset($changes['status']) && $body['status'] !== 'Active')) {
            $ended = revoke_all_sessions_for_user($id);
        }
        if ($changes) {
            $unlock = ($changes['status']['from'] ?? '') === 'Locked';
            log_event($unlock ? 'account_unlocked' : 'user_updated', (int) $authUser['user_id'], [
                'target_user_id' => $id,
                'changes'        => $changes,
                'sessions_ended' => $ended,
            ], isset($changes['role']) ? 'warning' : 'info');
        }
        Response::json($updated);
    }

    if ($method === 'DELETE') {
        require_authenticated_user($pdo, ['Admin']);
        if ((int) ($_GET['id'] ?? 0) === (int) $authUser['user_id']) {
            Response::error('You cannot archive your own account.', 403);
        }
        $archiveId = (int) ($_GET['id'] ?? 0);
        if ($crud->delete($archiveId)) {
            revoke_all_sessions_for_user($archiveId);
            log_event('user_archived', (int) $authUser['user_id'], ['target_user_id' => $archiveId], 'warning');
            Response::json(['message' => 'Deleted', 'user_id' => $archiveId]);
        }
        Response::error('User not found.', 404);
    }
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'user_id');
