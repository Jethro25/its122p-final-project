<?php
/**
 * /api/books_catalog.php
 * GET (list/show), POST (create), PUT (update), DELETE
 *
 * A book can belong to more than one category, so category_id on
 * BOOKS_CATALOG is kept only as the "primary" category (for backward
 * compatibility with the existing schema), while the full set of
 * categories for a book lives in the BOOK_CATEGORY_MAP join table.
 * Clients send/receive the full set as a `category_ids` array.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$crud = new Crud(
    pdo: $pdo,
    table: 'BOOKS_CATALOG',
    primaryKey: 'book_id',
    insertable: ['category_id', 'managed_by_admin_id', 'title', 'author', 'isbn'],
    required: ['category_id', 'managed_by_admin_id', 'title', 'author', 'isbn'],
);


$method = $_SERVER['REQUEST_METHOD'];
$authenticatedUser = require_authenticated_user($pdo);

/**
 * Normalizes whatever the client sent for categories into a clean,
 * de-duplicated array of ints. Accepts `category_ids` (array) and
 * falls back to a single `category_id` for older clients.
 */
function extract_category_ids(array $body): array
{
    if (!empty($body['category_ids']) && is_array($body['category_ids'])) {
        $ids = array_map('intval', $body['category_ids']);
    } elseif (!empty($body['category_id'])) {
        $ids = [(int) $body['category_id']];
    } else {
        $ids = [];
    }

    $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    return $ids;
}

/** Maps book_id => [category_id, ...] for every id in $bookIds. */
function fetch_category_ids(PDO $pdo, array $bookIds): array
{
    $bookIds = array_values(array_unique(array_map('intval', $bookIds)));
    if (!$bookIds) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($bookIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT `book_id`, `category_id` FROM `BOOK_CATEGORY_MAP` WHERE `book_id` IN ({$placeholders}) ORDER BY `category_id` ASC"
    );
    $stmt->execute($bookIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['book_id']][] = (int) $row['category_id'];
    }
    return $map;
}

/** Replaces the BOOK_CATEGORY_MAP rows for one book with $categoryIds. */
function save_category_map(PDO $pdo, int $bookId, array $categoryIds): void
{
    $pdo->prepare('DELETE FROM `BOOK_CATEGORY_MAP` WHERE `book_id` = :book_id')
        ->execute(['book_id' => $bookId]);

    if (!$categoryIds) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `BOOK_CATEGORY_MAP` (`book_id`, `category_id`) VALUES (:book_id, :category_id)'
    );
    foreach ($categoryIds as $categoryId) {
        $stmt->execute(['book_id' => $bookId, 'category_id' => $categoryId]);
    }
}

try {
    switch ($method) {

        case 'GET':
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $row = $crud->show($id);
                if (!$row) {
                    Response::error("Record with book_id = {$id} not found.", 404);
                }
                $categoryMap = fetch_category_ids($pdo, [$row['book_id']]);
                $row['category_ids'] = $categoryMap[(int) $row['book_id']] ?? [(int) $row['category_id']];
                Response::json($row);
            } else {
                $rows = $crud->index($_GET);
                $categoryMap = fetch_category_ids($pdo, array_column($rows, 'book_id'));
                foreach ($rows as &$row) {
                    $row['category_ids'] = $categoryMap[(int) $row['book_id']] ?? [(int) $row['category_id']];
                }
                unset($row);
                Response::json($rows);
            }
            break;

        case 'POST':
            if (!in_array($authenticatedUser['role'], ['Customer', 'Admin'], true)) {
                Response::error('Only Customers and Administrators may create book catalog entries.', 403);
            }
            $body = read_json_body();
            $categoryIds = extract_category_ids($body);

            if (!$categoryIds) {
                Response::error('Please select at least one category.', 422);
            }

            /* category_id keeps the first selected category, satisfying the
               existing NOT NULL foreign key on BOOKS_CATALOG. The schema also
               requires an admin owner; Customer submissions use the first
               active administrator rather than trusting a client-supplied ID. */
            $body['category_id'] = $categoryIds[0];
            if ($authenticatedUser['role'] === 'Customer') {
                $adminStmt = $pdo->query("SELECT user_id FROM `USER` WHERE role = 'Admin' AND status = 'Active' ORDER BY user_id ASC LIMIT 1");
                $adminId = $adminStmt->fetchColumn();
                if (!$adminId) {
                    Response::error('No active administrator is available to manage this catalog entry.', 503);
                }
                $body['managed_by_admin_id'] = (int) $adminId;
            } else {
                $body['managed_by_admin_id'] = (int) ($body['managed_by_admin_id'] ?? $authenticatedUser['user_id']);
            }

            $created = $crud->create($body);
            save_category_map($pdo, (int) $created['book_id'], $categoryIds);
            $created['category_ids'] = $categoryIds;

            Response::json($created, 201);
            break;

        case 'PUT':
        case 'PATCH':
            require_authenticated_user($pdo, ['Admin']);
            $id = $_GET['id'] ?? null;
            if ($id === null) {
                Response::error("Query parameter 'book_id' (as ?id=) is required for updates.", 400);
            }

            $body = read_json_body();
            $categoryIds = null;
            if (isset($body['category_ids']) || isset($body['category_id'])) {
                $categoryIds = extract_category_ids($body);
                if (!$categoryIds) {
                    Response::error('Please select at least one category.', 422);
                }
                $body['category_id'] = $categoryIds[0];
            }

            $updated = $crud->update($id, $body);
            if ($updated === null) {
                Response::error("Record with book_id = {$id} not found.", 404);
            }

            if ($categoryIds !== null) {
                save_category_map($pdo, (int) $id, $categoryIds);
            }
            $categoryMap = fetch_category_ids($pdo, [$id]);
            $updated['category_ids'] = $categoryMap[(int) $id] ?? [(int) $updated['category_id']];

            Response::json($updated);
            break;

        case 'DELETE':
            require_authenticated_user($pdo, ['Admin']);
            $id = $_GET['id'] ?? null;
            if ($id === null) {
                Response::error("Query parameter 'book_id' (as ?id=) is required for deletes.", 400);
            }
            /* BOOK_CATEGORY_MAP rows are removed automatically via ON DELETE CASCADE */
            $ok = $crud->delete($id);
            if (!$ok) {
                Response::error("Record with book_id = {$id} not found.", 404);
            }
            Response::json(['message' => 'Deleted', 'book_id' => $id]);
            break;

        default:
            Response::error('Method not allowed.', 405);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    $code = (int) ($e->errorInfo[1] ?? 0);
    if ($code === 1062) {
        Response::error('A record with these unique values already exists.', 409, ['details' => $e->getMessage()]);
    } elseif (in_array($code, [1451, 1452], true)) {
        Response::error('This operation violates a foreign key relationship.', 409, ['details' => $e->getMessage()]);
    } else {
        Response::error('Database error.', 500, ['details' => $e->getMessage()]);
    }
}
