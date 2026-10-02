<?php
/**
 * /api/user_books.php
 * GET (list/show), POST (create), PUT (update), DELETE
 *
 * Listings may carry an optional cover photo (`cover_image`). The browser
 * shrinks the photo to a small JPEG and sends it as a data URL, which is
 * stored in the database (Vercel has no permanent disk for uploads).
 */
require_once __DIR__ . '/../book-marketplace-backend/lib/bootstrap.php';

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
            FROM `USER_BOOKS` ub WHERE ub.inventory_id IN ({$in})";
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
            $del = $pdo->prepare('DELETE FROM `USER_BOOKS` WHERE inventory_id IN (' . implode(',', $dMarks) . ") AND status = 'Removed'");
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

/* One-time migration: add the cover_image column if this database lacks it */
try {
    $pdo->query('SELECT `cover_image` FROM `USER_BOOKS` LIMIT 0');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE `USER_BOOKS` ADD COLUMN `cover_image` MEDIUMTEXT NULL');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    $authUser = require_authenticated_user($pdo);
    if ($authUser['role'] === 'Customer') {
        delete_listings($pdo, $authUser, [(int) ($_GET['id'] ?? 0)]);
    }
    // Staff/Admin fall through to the normal delete below
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $authUser = require_authenticated_user($pdo);
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true) ?: [];

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
        $find = $pdo->prepare("SELECT inventory_id FROM `USER_BOOKS` WHERE inventory_id IN ({$in}) AND status = :from{$ownerSql}");
        $findParams = $params; unset($findParams['to']);
        $find->execute($findParams);
        $changeable = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));

        if ($changeable) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE `USER_BOOKS` SET status = :to WHERE inventory_id IN ({$in}) AND status = :from{$ownerSql}");
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
);

dispatch_crud_request($crud, 'inventory_id');
