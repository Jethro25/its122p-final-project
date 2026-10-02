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

/* One-time migration: add the cover_image column if this database lacks it */
try {
    $pdo->query('SELECT `cover_image` FROM `USER_BOOKS` LIMIT 0');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE `USER_BOOKS` ADD COLUMN `cover_image` MEDIUMTEXT NULL');
}

$method = $_SERVER['REQUEST_METHOD'];

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
        $own = $pdo->prepare('SELECT seller_id FROM `USER_BOOKS` WHERE inventory_id = :id');
        $own->execute(['id' => $id]);
        $sellerId = $own->fetchColumn();
        if ($sellerId !== false && (int) $sellerId !== (int) $authUser['user_id']) {
            Response::error('You can only change your own listings.', 403);
        }
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
