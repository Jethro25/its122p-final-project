<?php
/**
 * /api/user_books.php
 * GET (list/show), POST (create), PUT (update), DELETE
 *
 * Listings may carry an optional cover photo (`cover_image`). The browser
 * shrinks the photo to a small JPEG and sends it as a data URL, which is
 * stored in the database (Vercel has no permanent disk for uploads).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

/* Make sure USER_BOOKS.status accepts 'Removed' (older databases lack it) */
function ensure_removed_status(PDO $pdo): void
{
    $col = $pdo->query("SHOW COLUMNS FROM `USER_BOOKS` LIKE 'status'")->fetch();
    if ($col && strpos((string) $col['Type'], "'Removed'") === false) {
        $pdo->exec("ALTER TABLE `USER_BOOKS` MODIFY `status` ENUM('Available','In_transaction','Sold','Traded','Removed','Reserved','Delisted') NOT NULL DEFAULT 'Available'");
    }
}

/* ── PERMANENT DELETE ───────────────────────────────────────────────────
   POST /api/user_books.php?action=bulk_delete   { "ids": [3, 7] }
   (a customer's DELETE ?id=7 is handled the same way)
   A listing is deleted for good only if it belongs to you, is already in
   Removed, and has never been part of a purchase or trade — so nobody
   else's transaction history is lost. Everything else is skipped. */
function delete_listings(PDO $pdo, array $authUser, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) Response::error('Choose at least one listing.', 422);
    if (count($ids) > 200) Response::error('You can delete up to 200 listings at a time.', 422);

    $params = [];
    $marks = [];
    foreach ($ids as $i => $id) { $marks[] = ":id{$i}"; $params["id{$i}"] = $id; }
    $in = implode(',', $marks);

    $sql = "SELECT ub.inventory_id, ub.seller_id, ub.status,
                   (SELECT COUNT(*) FROM `TRANSACTIONS` t
                     WHERE t.requested_inventory_id = ub.inventory_id
                        OR t.offered_inventory_id   = ub.inventory_id) AS tx_count
            FROM `USER_BOOKS` ub WHERE ub.inventory_id IN ({$in}) AND ub.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $deletable = [];
    $skipped = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['inventory_id'];
        if ($authUser['role'] === 'Customer' && (int) $row['seller_id'] !== (int) $authUser['user_id']) {
            $skipped[] = ['id' => $id, 'reason' => 'not_yours'];
        } elseif ($row['status'] !== 'Removed') {
            $skipped[] = ['id' => $id, 'reason' => 'not_removed'];
        } elseif ((int) $row['tx_count'] > 0) {
            $skipped[] = ['id' => $id, 'reason' => 'has_history'];
        } else {
            $deletable[] = $id;
        }
    }

    if ($deletable) {
        $dParams = [];
        $dMarks = [];
        foreach ($deletable as $i => $id) { $dMarks[] = ":d{$i}"; $dParams["d{$i}"] = $id; }
        $pdo->beginTransaction();
        try {
            // Soft delete: the row stays in the database for logging and is
            // hidden from the app. deleted_by records who deleted it.
            $dParams['by'] = (int) $authUser['user_id'];
            $del = $pdo->prepare('UPDATE `USER_BOOKS` SET deleted_at = NOW(), deleted_by = :by
                                  WHERE inventory_id IN (' . implode(',', $dMarks) . ") AND status = 'Removed' AND deleted_at IS NULL");
            $del->execute($dParams);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::error('Could not delete the listings. Nothing was deleted.', 500);
        }
    }

    Response::json([
        'deleted'     => count($deletable),
        'deleted_ids' => $deletable,
        'skipped'     => $skipped,
    ]);
}

/* One-time migration: soft-delete logging columns */
try {
    $pdo->query('SELECT `deleted_at`, `deleted_by` FROM `USER_BOOKS` LIMIT 0');
} catch (PDOException $e) {
    $cols = $pdo->query("SHOW COLUMNS FROM `USER_BOOKS`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deleted_at', $cols, true)) $pdo->exec('ALTER TABLE `USER_BOOKS` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL');
    if (!in_array('deleted_by', $cols, true)) $pdo->exec('ALTER TABLE `USER_BOOKS` ADD COLUMN `deleted_by` INT UNSIGNED NULL DEFAULT NULL');
}

/* One-time migration: add the cover_image column if this database lacks it */
try {
    $pdo->query('SELECT `cover_image` FROM `USER_BOOKS` LIMIT 0');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE `USER_BOOKS` ADD COLUMN `cover_image` MEDIUMTEXT NULL');
}

$method = $_SERVER['REQUEST_METHOD'];

/* ── COVER PHOTOS ───────────────────────────────────────────────────────
   GET /api/user_books.php?action=cover&id=7&v=123
   Returns the photo itself as an image (public, so <img> tags can load it,
   and cached by the browser; `v` changes whenever the photo changes).
   Book lists never include the photo data — sending every photo inside the
   list would exceed Vercel's 4.5 MB response limit after a dozen photos. */
if ($method === 'GET' && ($_GET['action'] ?? '') === 'cover') {
    $stmt = $pdo->prepare('SELECT cover_image FROM `USER_BOOKS` WHERE inventory_id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => (int) ($_GET['id'] ?? 0)]);
    $img = (string) ($stmt->fetchColumn() ?: '');
    if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $img, $m)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        exit('No cover photo.');
    }
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . $m[1]);
    header('Cache-Control: public, max-age=31536000, immutable');
    echo base64_decode($m[2]);
    exit;
}

/* Book lists: every column except the photo itself, plus has_cover / cover_v */
if ($method === 'GET') {
    require_authenticated_user($pdo);
    $where = ['deleted_at IS NULL'];
    $params = [];
    foreach (['seller_id', 'book_id', 'status', 'listing_type'] as $col) {
        if (isset($_GET[$col]) && $_GET[$col] !== '') { $where[] = "`{$col}` = :{$col}"; $params[$col] = $_GET[$col]; }
    }
    if (isset($_GET['id'])) { $where[] = 'inventory_id = :id'; $params['id'] = (int) $_GET['id']; }
    $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 1000)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $sql = "SELECT inventory_id, book_id, seller_id, listing_type, price, `condition`, status, listed_at,
                   (cover_image IS NOT NULL AND cover_image <> '') AS has_cover,
                   CRC32(cover_image) AS cover_v
            FROM `USER_BOOKS` WHERE " . implode(' AND ', $where) . "
            ORDER BY inventory_id ASC LIMIT {$limit} OFFSET {$offset}";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Fallback if CRC32() isn't supported: use the photo's size as the version
        try {
            $stmt = $pdo->prepare(str_replace('CRC32(cover_image)', 'LENGTH(cover_image)', $sql));
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e2) {
            database_error_response($e2);
        }
    }
    foreach ($rows as &$row) {
        $row['has_cover'] = (bool) $row['has_cover'];
        if (!$row['has_cover']) $row['cover_v'] = null;
    }
    unset($row);
    if (isset($_GET['id'])) {
        if (!$rows) Response::error('Listing not found.', 404);
        Response::json($rows[0]);
    }
    Response::json($rows);
}

if ($method === 'DELETE') {
    $authUser = require_authenticated_user($pdo);
    if ($authUser['role'] === 'Customer') {
        delete_listings($pdo, $authUser, [(int) ($_GET['id'] ?? 0)]);
    }
    // Staff/Admin fall through to the normal delete below
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $authUser = require_authenticated_user($pdo);
    $payload = request_body();

    /* Validate the cover photo: must be a small JPEG/PNG/WebP data URL */
    if (array_key_exists('cover_image', $payload) && $payload['cover_image'] !== null && $payload['cover_image'] !== '') {
        $img = (string) $payload['cover_image'];
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $img)) {
            Response::error('Cover photo must be a JPEG, PNG or WebP image.', 422);
        }
        if (strlen($img) > 700000) {
            Response::error('Cover photo is too large. Please choose a smaller image.', 413);
        }
    }

    /* Customers may only edit their own listings */
    if (($method === 'PUT' || $method === 'PATCH') && $authUser['role'] === 'Customer') {
        $id = $_GET['id'] ?? null;
        $own = $pdo->prepare('SELECT seller_id, status FROM `USER_BOOKS` WHERE inventory_id = :id');
        $own->execute(['id' => $id]);
        $current = $own->fetch();
        if ($current && (int) $current['seller_id'] !== (int) $authUser['user_id']) {
            Response::error('You can only change your own listings.', 403);
        }

        /* Removing / re-listing: a seller may only take an available book off
           the shelf (Removed) or put a removed one back (Available). Books with
           a pending purchase or trade, or already sold/traded, can't be changed. */
        if ($current && array_key_exists('status', $payload)) {
            $from = (string) $current['status'];
            $to = (string) $payload['status'];
            $allowed = ($from === 'Available' && $to === 'Removed') || ($from === 'Removed' && $to === 'Available');
            if (!$allowed) {
                $msg = $from === 'In_transaction'
                    ? 'This book has a pending purchase or trade request, so it can\'t be removed right now.'
                    : 'This listing can no longer be changed.';
                Response::error($msg, 409);
            }
        }
    }

    /* Older databases may be missing 'Removed' in the status list — add it */
    if (($method === 'PUT' || $method === 'PATCH') && array_key_exists('status', $payload)) {
        ensure_removed_status($pdo);
    }

    /* ── BULK: remove or put back several of your listings in one request ──
       POST /api/user_books.php?action=bulk_status
       { "ids": [3, 7, 9], "status": "Removed" | "Available" }
       Only listings you own (any listing for Staff/Admin) that are currently
       Available (to remove) or Removed (to put back) are changed; anything
       else is skipped and reported back. */
    if ($method === 'POST' && ($_GET['action'] ?? '') === 'bulk_delete') {
        delete_listings($pdo, $authUser, (array) ($payload['ids'] ?? []));
    }

    if ($method === 'POST' && ($_GET['action'] ?? '') === 'bulk_status') {
        $to = (string) ($payload['status'] ?? '');
        if (!in_array($to, ['Removed', 'Available'], true)) {
            Response::error('Status must be "Removed" or "Available".', 422);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['ids'] ?? [])))));
        if (!$ids) Response::error('Choose at least one listing.', 422);
        if (count($ids) > 200) Response::error('You can change up to 200 listings at a time.', 422);

        ensure_removed_status($pdo);
        $from = $to === 'Removed' ? 'Available' : 'Removed';

        $params = ['to' => $to, 'from' => $from];
        $marks = [];
        foreach ($ids as $i => $id) { $marks[] = ":id{$i}"; $params["id{$i}"] = $id; }
        $in = implode(',', $marks);

        $ownerSql = '';
        if ($authUser['role'] === 'Customer') {
            $ownerSql = ' AND seller_id = :uid';
            $params['uid'] = (int) $authUser['user_id'];
        }

        // Which of the requested listings can actually change?
        $find = $pdo->prepare("SELECT inventory_id FROM `USER_BOOKS` WHERE inventory_id IN ({$in}) AND status = :from AND deleted_at IS NULL{$ownerSql}");
        $findParams = $params; unset($findParams['to']);
        $find->execute($findParams);
        $changeable = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));

        if ($changeable) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE `USER_BOOKS` SET status = :to WHERE inventory_id IN ({$in}) AND status = :from AND deleted_at IS NULL{$ownerSql}");
                $upd->execute($params);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::error('Could not update the listings. Nothing was changed.', 500);
            }
        }

        Response::json([
            'status'      => $to,
            'updated'     => count($changeable),
            'updated_ids' => $changeable,
            'skipped_ids' => array_values(array_diff($ids, $changeable)),
        ]);
    }
}

$crud = new Crud(
    pdo: $pdo,
    table: 'USER_BOOKS',
    primaryKey: 'inventory_id',
    insertable: ['book_id', 'seller_id', 'listing_type', 'price', 'condition', 'status', 'cover_image'],
    required: ['book_id', 'seller_id', 'listing_type', 'condition'],
    enums: [
        'listing_type' => ['For_trade', 'For_sale', 'Both'],
        'condition'    => ['New', 'Good', 'Acceptable'],
        'status'       => ['Available', 'In_transaction', 'Sold', 'Traded', 'Removed'],
    ],
    softDeleteColumn: 'deleted_at',   // deleted listings stay in the DB but are hidden from the app
);

/* ── Listing rules (create + edit) ───────────────────────────────────────
   • A sale listing (For_sale / Both) needs a price from 1 to 9,999.99.
   • A trade-only listing has no price.
   • Customers always list as themselves, start as Available, and can only
     edit price, listing type, condition, photo, and Removed/Available. */
function normalize_listing_price(string $type, $price): ?string
{
    if ($type === 'For_trade') return null;
    if ($price === null || $price === '' || !is_numeric($price)) {
        Response::error('Please enter a price for a sale listing.', 422);
    }
    $value = round((float) $price, 2);
    if ($value < 1 || $value > 9999.99) Response::error('Price must be between ₱1 and ₱9,999.99.', 422);
    return number_format($value, 2, '.', '');
}

try {
    if ($method === 'POST' && !isset($_GET['action'])) {
        $isCustomer = !is_staff_or_admin($authUser);
        $type = (string) ($payload['listing_type'] ?? '');
        if (!in_array($type, ['For_sale', 'For_trade', 'Both'], true)) {
            Response::error('Listing type must be For Sale, For Trade, or Both.', 422);
        }
        $book = $pdo->prepare('SELECT book_id FROM `BOOKS_CATALOG` WHERE book_id = :id AND deleted_at IS NULL');
        $book->execute(['id' => (int) ($payload['book_id'] ?? 0)]);
        if (!$book->fetch()) Response::error('That book is not in the catalog.', 422);

        $data = [
            'book_id'      => (int) $payload['book_id'],
            'seller_id'    => $isCustomer ? (int) $authUser['user_id'] : (int) ($payload['seller_id'] ?? $authUser['user_id']),
            'listing_type' => $type,
            'price'        => normalize_listing_price($type, $payload['price'] ?? null),
            'condition'    => $payload['condition'] ?? null,
            'status'       => $isCustomer ? 'Available' : ($payload['status'] ?? 'Available'),
            'cover_image'  => $payload['cover_image'] ?? null,
        ];
        Response::json($crud->create($data), 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = (int) ($_GET['id'] ?? 0);
        $current = $crud->show($id);
        if (!$current) Response::error('Listing not found.', 404);

        if (!is_staff_or_admin($authUser)) {
            $allowed = ['price', 'listing_type', 'condition', 'cover_image', 'status'];
            if (array_diff(array_keys($payload), $allowed)) {
                Response::error('You can only change the price, type, condition, photo, or shelf status.', 422);
            }
            $editingDetails = array_intersect(array_keys($payload), ['price', 'listing_type', 'condition']);
            if ($editingDetails && !in_array($current['status'], ['Available', 'Removed'], true)) {
                Response::error('This book has a pending or finished sale/trade, so its details can no longer change.', 409);
            }
        }

        $update = $payload;
        if (array_key_exists('price', $payload) || array_key_exists('listing_type', $payload)) {
            $type = (string) ($payload['listing_type'] ?? $current['listing_type']);
            $update['price'] = normalize_listing_price($type, array_key_exists('price', $payload) ? $payload['price'] : $current['price']);
        }
        $updated = $crud->update($id, $update);
        Response::json($updated);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'inventory_id');
