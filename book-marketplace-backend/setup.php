<?php
/**
 * LIBROWSE - One-click database setup
 * 
 * Place this file in book-marketplace-backend/
 * Visit http://127.0.0.1:8000/setup.php in your browser
 * Delete this file after setup is complete.
 */

/* This script DROPS and recreates every table. It must never be reachable
   from the internet: allow it only from this computer, never on Vercel. */
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (PHP_SAPI !== 'cli' && (getenv('VERCEL') || !in_array($remote, ['127.0.0.1', '::1'], true))) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Setup can only be run from the computer hosting Librowse.');
}

$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbUser = 'root';
$dbPass = '';
$dbName = 'book_marketplace';

$steps = [];
$success = true;

function step(string $label, bool $ok, string $detail = ''): array {
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

try {
    // Connect without selecting a database first
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $steps[] = step('Connected to MySQL', true, "Host: {$dbHost}:{$dbPort}, User: {$dbUser}");
} catch (PDOException $e) {
    $steps[] = step('Connected to MySQL', false, $e->getMessage());
    $success = false;
}

if ($success) {
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
        $steps[] = step("Created/selected database '{$dbName}'", true);
    } catch (PDOException $e) {
        $steps[] = step("Created/selected database '{$dbName}'", false, $e->getMessage());
        $success = false;
    }
}

if ($success) {
    $sql = <<<SQL
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `SYSTEM_RECORDS`;
DROP TABLE IF EXISTS `REPORTS`;
DROP TABLE IF EXISTS `REFUND_REQUEST`;
DROP TABLE IF EXISTS `TRANSACTIONS`;
DROP TABLE IF EXISTS `USER_BOOKS`;
DROP TABLE IF EXISTS `BOOK_CATEGORY_MAP`;
DROP TABLE IF EXISTS `BOOKS_CATALOG`;
DROP TABLE IF EXISTS `BOOK_CATEGORIES`;
DROP TABLE IF EXISTS `USER`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `USER` (
  `user_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('Customer','Staff','Admin') NOT NULL DEFAULT 'Customer',
  `status` ENUM('Active','Suspended','Banned','Pending Verification') NOT NULL DEFAULT 'Pending Verification',
  `permission` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `BOOK_CATEGORIES` (
  `category_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `created_by_admin_id` INT UNSIGNED NOT NULL,
  `category_name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  PRIMARY KEY (`category_id`),
  CONSTRAINT `fk_bc_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `BOOKS_CATALOG` (
  `book_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `managed_by_admin_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `author` VARCHAR(255) NOT NULL,
  `isbn` VARCHAR(20) NOT NULL,
  PRIMARY KEY (`book_id`),
  CONSTRAINT `fk_bcat_admin` FOREIGN KEY (`managed_by_admin_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `BOOK_CATEGORY_MAP` (
  `book_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`book_id`, `category_id`),
  CONSTRAINT `fk_bcm_book` FOREIGN KEY (`book_id`) REFERENCES `BOOKS_CATALOG`(`book_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bcm_cat` FOREIGN KEY (`category_id`) REFERENCES `BOOK_CATEGORIES`(`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `USER_BOOKS` (
  `inventory_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `book_id` INT UNSIGNED NOT NULL,
  `seller_id` INT UNSIGNED NOT NULL,
  `listing_type` ENUM('For_trade','For_sale','Both') NOT NULL,
  `price` DECIMAL(10,2) DEFAULT NULL,
  `condition` ENUM('New','Good','Acceptable') NOT NULL,
  `status` ENUM('Available','In_transaction','Sold','Traded','Removed') NOT NULL DEFAULT 'Available',
  `listed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`),
  CONSTRAINT `fk_ub_book` FOREIGN KEY (`book_id`) REFERENCES `BOOKS_CATALOG`(`book_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ub_seller` FOREIGN KEY (`seller_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `TRANSACTIONS` (
  `transaction_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `buyer_id` INT UNSIGNED NOT NULL,
  `requested_inventory_id` INT UNSIGNED NOT NULL,
  `offered_inventory_id` INT UNSIGNED DEFAULT NULL,
  `managed_by_staff_id` INT UNSIGNED DEFAULT NULL,
  `transaction_type` ENUM('Purchase','Trade') NOT NULL,
  `amount_paid` DECIMAL(10,2) DEFAULT NULL,
  `status` ENUM('Pending','Accepted','Completed','Cancelled','Disputed') NOT NULL DEFAULT 'Pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  CONSTRAINT `fk_tx_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tx_req` FOREIGN KEY (`requested_inventory_id`) REFERENCES `USER_BOOKS`(`inventory_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tx_off` FOREIGN KEY (`offered_inventory_id`) REFERENCES `USER_BOOKS`(`inventory_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_staff` FOREIGN KEY (`managed_by_staff_id`) REFERENCES `USER`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `REFUND_REQUEST` (
  `refund_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `transaction_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `processed_by_staff_id` INT UNSIGNED DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`refund_id`),
  CONSTRAINT `fk_ref_tx` FOREIGN KEY (`transaction_id`) REFERENCES `TRANSACTIONS`(`transaction_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ref_cust` FOREIGN KEY (`customer_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ref_staff` FOREIGN KEY (`processed_by_staff_id`) REFERENCES `USER`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `REPORTS` (
  `report_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `submitted_by_id` INT UNSIGNED NOT NULL,
  `reviewed_by_id` INT UNSIGNED DEFAULT NULL,
  `report_category` ENUM('Verification_Form','Seller_Application','User_Violation','Listing_Dispute','General_Feedback') NOT NULL,
  `related_entity_type` ENUM('User','Book_Listing','Transaction','None') NOT NULL DEFAULT 'None',
  `form_data` TEXT DEFAULT NULL,
  `status` ENUM('Pending','Under_Review','Approved','Rejected','Resolved','Dismissed') NOT NULL DEFAULT 'Pending',
  `resolution_notes` TEXT DEFAULT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  CONSTRAINT `fk_rep_sub` FOREIGN KEY (`submitted_by_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rep_rev` FOREIGN KEY (`reviewed_by_id`) REFERENCES `USER`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `SYSTEM_RECORDS` (
  `record_id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `admin_id` INT UNSIGNED NOT NULL,
  `record_type` ENUM('Audit_Log','Financial_Transaction_Record') NOT NULL,
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`record_id`),
  CONSTRAINT `fk_sr_admin` FOREIGN KEY (`admin_id`) REFERENCES `USER`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    try {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') $pdo->exec($statement);
        }
        $steps[] = step('Created all tables', true, 'USER, BOOK_CATEGORIES, BOOKS_CATALOG, BOOK_CATEGORY_MAP, USER_BOOKS, TRANSACTIONS, REFUND_REQUEST, REPORTS, SYSTEM_RECORDS');
    } catch (PDOException $e) {
        $steps[] = step('Created all tables', false, $e->getMessage());
        $success = false;
    }
}

if ($success) {
    // Password for all accounts: "password"
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    
    $seed = <<<SQL
INSERT INTO `USER` (`user_id`,`username`,`email`,`password_hash`,`role`,`status`,`permission`,`created_at`) VALUES
(1,'alice_wong','alice.wong@example.com','{$hash}','Admin','Active','{"can_manage_categories":true,"can_manage_users":true}','2024-01-05 09:12:00'),
(2,'marcus_lee','marcus.lee@example.com','{$hash}','Admin','Active','{"can_manage_categories":true,"can_manage_users":true}','2024-01-10 14:20:00'),
(3,'priya_singh','priya.singh@example.com','{$hash}','Staff','Active','{"can_review_reports":true}','2024-02-01 08:00:00'),
(4,'daniel_kim','daniel.kim@example.com','{$hash}','Staff','Active','{"can_review_reports":true,"can_process_refunds":true}','2024-02-15 11:45:00'),
(5,'emma_clarke','emma.clarke@example.com','{$hash}','Customer','Active','{}','2024-03-01 10:00:00'),
(6,'liam_brown','liam.brown@example.com','{$hash}','Customer','Active','{}','2024-03-05 16:30:00'),
(7,'sofia_garcia','sofia.garcia@example.com','{$hash}','Customer','Suspended','{}','2024-03-12 09:15:00'),
(8,'noah_martin','noah.martin@example.com','{$hash}','Customer','Active','{}','2024-03-20 13:00:00'),
(9,'ava_wilson','ava.wilson@example.com','{$hash}','Customer','Pending Verification','{}','2024-04-02 07:50:00'),
(10,'ethan_moore','ethan.moore@example.com','{$hash}','Customer','Banned','{}','2024-04-10 18:22:00');

INSERT INTO `BOOK_CATEGORIES` VALUES
(1,1,'Fiction','Novels and fictional narratives'),
(2,2,'Non-Fiction','Factual and informational books'),
(3,1,'Science Fiction & Fantasy','Speculative and fantastical worlds'),
(4,2,'Mystery & Thriller','Suspense, crime and thriller novels'),
(5,1,'Romance','Romantic fiction across sub-genres'),
(6,2,'Biography & Memoir','Life stories and personal accounts'),
(7,1,'Children\'s Books','Books for young readers'),
(8,2,'Academic & Textbooks','Educational and course textbooks'),
(9,1,'Self-Help','Personal development and wellness'),
(10,2,'Comics & Graphic Novels','Illustrated storytelling collections');

INSERT INTO `BOOKS_CATALOG` (`book_id`,`managed_by_admin_id`,`title`,`author`,`isbn`) VALUES
(1,1,'The Silent Orchard','Marie Devon','9780134190441'),
(2,2,'A Brief History of Everything','Daniel Cho','9780262033849'),
(3,1,'Starlight Exiles','Renata Voss','9780345391804'),
(4,2,'The Last Alibi','Connor Hayes','9780307474279'),
(5,1,'Autumn in Verona','Isabel Marsh','9780451524936'),
(6,2,'Becoming Whole','Grace Nakamura','9780670785936'),
(7,1,'The Dragon Who Forgot to Roar','Lucy Pemberton','9780062315008'),
(8,2,'Foundations of Algorithms','T. Cormen','9780262033856'),
(9,1,'Atomic Habits Revisited','Owen Clarke','9780593189337'),
(10,2,'Moonlit Rebellion, Vol. 1','Kenji Arata','9781401290421');

INSERT INTO `BOOK_CATEGORY_MAP` VALUES
(1,1),(2,2),(3,3),(3,5),(4,4),(4,9),(5,5),(6,6),(6,9),(7,7),(8,8),(9,9),(10,10),(10,3);

INSERT INTO `USER_BOOKS` VALUES
(1,1,5,'For_sale',12.99,'Good','Available','2024-04-01 09:00:00'),
(2,2,6,'For_trade',NULL,'Acceptable','Available','2024-04-02 10:30:00'),
(3,3,7,'Both',15.50,'New','In_transaction','2024-04-03 11:15:00'),
(4,4,8,'For_sale',9.75,'Good','Sold','2024-04-04 14:00:00'),
(5,5,9,'For_trade',NULL,'Good','Traded','2024-04-05 08:45:00'),
(6,6,10,'For_sale',18.00,'New','Available','2024-04-06 12:20:00'),
(7,7,5,'Both',7.25,'Acceptable','Available','2024-04-07 15:40:00'),
(8,8,6,'For_sale',45.00,'Good','Available','2024-04-08 09:50:00'),
(9,9,7,'For_trade',NULL,'New','Removed','2024-04-09 13:10:00'),
(10,10,8,'For_sale',22.30,'Good','In_transaction','2024-04-10 16:05:00');

INSERT INTO `TRANSACTIONS` VALUES
(1,9,1,NULL,3,'Purchase',12.99,'Completed','2024-04-11 10:00:00'),
(2,10,2,5,NULL,'Trade',NULL,'Completed','2024-04-12 11:30:00'),
(3,6,3,NULL,4,'Purchase',15.50,'Pending','2024-04-13 09:20:00'),
(4,5,4,NULL,3,'Purchase',9.75,'Completed','2024-04-14 13:45:00'),
(5,8,6,NULL,NULL,'Purchase',18.00,'Accepted','2024-04-15 15:10:00'),
(6,7,7,9,4,'Trade',NULL,'Completed','2024-04-16 08:35:00'),
(7,10,8,NULL,3,'Purchase',45.00,'Disputed','2024-04-17 14:25:00'),
(8,9,10,NULL,NULL,'Purchase',22.30,'Pending','2024-04-18 09:55:00'),
(9,6,1,NULL,4,'Purchase',12.99,'Cancelled','2024-04-19 12:15:00'),
(10,5,3,NULL,3,'Purchase',15.50,'Completed','2024-04-20 17:00:00');

INSERT INTO `REFUND_REQUEST` VALUES
(1,1,9,3,'Book arrived with water damage','Approved','2024-04-12 09:00:00'),
(2,4,5,4,'Wrong edition shipped','Approved','2024-04-15 10:15:00'),
(3,7,10,3,'Item never received','Pending','2024-04-18 11:30:00'),
(4,9,6,NULL,'Buyer changed their mind','Rejected','2024-04-19 13:00:00'),
(5,10,5,4,'Missing pages in book','Approved','2024-04-21 08:45:00'),
(6,3,6,NULL,'Seller cancelled after payment','Pending','2024-04-13 15:20:00'),
(7,5,8,3,'Condition not as described','Rejected','2024-04-16 09:10:00'),
(8,2,10,4,'Trade item was counterfeit','Approved','2024-04-13 14:00:00'),
(9,6,7,NULL,'Duplicate charge on card','Pending','2024-04-17 10:40:00'),
(10,8,9,3,'Book listing was misleading','Rejected','2024-04-19 16:25:00');

INSERT INTO `REPORTS` (`report_id`,`submitted_by_id`,`reviewed_by_id`,`report_category`,`related_entity_type`,`form_data`,`status`,`resolution_notes`,`submitted_at`,`resolved_at`) VALUES
(1,7,3,'User_Violation','User','{"target_user_id":10,"details":"Abusive messages"}','Resolved','Warning issued to user','2024-04-01 09:00:00','2024-04-03 10:00:00'),
(2,9,4,'Listing_Dispute','Book_Listing','{"inventory_id":8,"issue":"Price mismatch"}','Approved','Listing price corrected','2024-04-02 11:20:00','2024-04-04 09:30:00'),
(3,5,NULL,'General_Feedback','None','{"comment":"Great platform"}','Pending',NULL,'2024-04-03 14:00:00',NULL),
(4,10,3,'Seller_Application','None','{"requested_role":"Staff"}','Rejected','Insufficient sales history','2024-04-04 08:45:00','2024-04-06 12:00:00'),
(5,6,4,'Verification_Form','User','{"id_document":"uploaded"}','Approved','Identity verified','2024-04-05 10:30:00','2024-04-07 09:15:00'),
(6,8,NULL,'User_Violation','Transaction','{"transaction_id":7,"details":"Suspected fraud"}','Under_Review',NULL,'2024-04-06 13:10:00',NULL),
(7,7,3,'Listing_Dispute','Book_Listing','{"inventory_id":3,"issue":"Item not as described"}','Resolved','Refund recommended','2024-04-07 15:45:00','2024-04-09 11:00:00'),
(8,9,4,'General_Feedback','None','{"comment":"App crashes on checkout"}','Dismissed','Could not reproduce','2024-04-08 09:20:00','2024-04-10 14:30:00'),
(9,10,NULL,'User_Violation','User','{"target_user_id":7,"details":"Spam listings"}','Pending',NULL,'2024-04-09 12:00:00',NULL),
(10,5,3,'Seller_Application','None','{"requested_role":"Staff"}','Approved','Approved after review','2024-04-10 16:15:00','2024-04-12 10:45:00');

INSERT INTO `SYSTEM_RECORDS` VALUES
(1,1,'Audit_Log','{"action":"created_category","category_id":1}','2024-01-05 09:15:00'),
(2,2,'Audit_Log','{"action":"created_category","category_id":2}','2024-01-10 14:25:00'),
(3,1,'Financial_Transaction_Record','{"transaction_id":1,"amount":12.99}','2024-04-11 10:05:00'),
(4,2,'Financial_Transaction_Record','{"transaction_id":4,"amount":9.75}','2024-04-14 13:50:00'),
(5,1,'Audit_Log','{"action":"suspended_user","user_id":7}','2024-03-13 08:00:00'),
(6,2,'Audit_Log','{"action":"banned_user","user_id":10}','2024-04-11 09:30:00'),
(7,1,'Financial_Transaction_Record','{"transaction_id":7,"amount":45.00}','2024-04-17 14:30:00'),
(8,2,'Audit_Log','{"action":"approved_refund","refund_id":1}','2024-04-12 09:05:00'),
(9,1,'Financial_Transaction_Record','{"transaction_id":10,"amount":15.50}','2024-04-20 17:05:00'),
(10,2,'Audit_Log','{"action":"rejected_report","report_id":4}','2024-04-06 12:05:00');
SQL;

    try {
        foreach (array_filter(array_map('trim', explode(';', $seed))) as $statement) {
            if ($statement !== '') $pdo->exec($statement);
        }
        $steps[] = step('Inserted all sample data', true, '10 users, 10 books, 10 listings, 10 transactions, 10 refunds, 10 reports');
    } catch (PDOException $e) {
        $steps[] = step('Inserted all sample data', false, $e->getMessage());
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librowse Database Setup</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #eef4ff, #f6f3ff, #ecfeff); min-height: 100vh; padding: 40px 20px; }
        .card { max-width: 680px; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 20px 50px rgba(15,23,42,0.12); overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #1d4ed8, #7c3aed, #0891b2); padding: 28px 32px; color: white; }
        .card-header h1 { font-size: 22px; font-weight: 800; }
        .card-header p { font-size: 13px; opacity: 0.8; margin-top: 4px; }
        .card-body { padding: 28px 32px; }
        .step { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .step:last-child { border-bottom: none; }
        .icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; margin-top: 1px; }
        .icon.ok { background: #dcfce7; color: #15803d; }
        .icon.fail { background: #fee2e2; color: #dc2626; }
        .step-label { font-size: 14px; font-weight: 700; color: #1e293b; }
        .step-detail { font-size: 12px; color: #64748b; margin-top: 3px; word-break: break-all; }
        .result { margin-top: 24px; padding: 20px; border-radius: 12px; text-align: center; }
        .result.success { background: #f0fdf4; border: 1px solid #86efac; }
        .result.failure { background: #fef2f2; border: 1px solid #fca5a5; }
        .result h2 { font-size: 18px; font-weight: 800; margin-bottom: 8px; }
        .result.success h2 { color: #15803d; }
        .result.failure h2 { color: #dc2626; }
        .result p { font-size: 13px; color: #475569; margin-bottom: 4px; }
        .accounts { margin-top: 20px; background: #f8fafc; border-radius: 10px; padding: 16px; text-align: left; }
        .accounts h3 { font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .account { display: flex; justify-content: space-between; font-size: 13px; padding: 5px 0; border-bottom: 1px solid #e2e8f0; }
        .account:last-child { border-bottom: none; }
        .account .name { font-weight: 600; color: #1e293b; }
        .account .role { color: #64748b; }
        .btn { display: inline-block; margin-top: 16px; padding: 11px 24px; background: linear-gradient(135deg,#2563eb,#7c3aed); color: white; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 14px; }
        .warning { margin-top: 16px; padding: 12px 16px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; font-size: 12px; color: #92400e; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>📚 Librowse Database Setup</h1>
        <p>Setting up the book_marketplace database automatically…</p>
    </div>
    <div class="card-body">
        <?php foreach ($steps as $s): ?>
        <div class="step">
            <div class="icon <?= $s['ok'] ? 'ok' : 'fail' ?>">
                <?= $s['ok'] ? '✓' : '✗' ?>
            </div>
            <div>
                <div class="step-label"><?= htmlspecialchars($s['label']) ?></div>
                <?php if ($s['detail']): ?>
                <div class="step-detail"><?= htmlspecialchars($s['detail']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($success): ?>
        <div class="result success">
            <h2>✅ Setup Complete!</h2>
            <p>Database and all tables created successfully.</p>
            <p>All demo accounts use password: <strong>password</strong></p>
            <div class="accounts">
                <h3>Demo Accounts</h3>
                <div class="account"><span class="name">emma_clarke</span><span class="role">Customer</span></div>
                <div class="account"><span class="name">liam_brown</span><span class="role">Customer</span></div>
                <div class="account"><span class="name">noah_martin</span><span class="role">Customer</span></div>
                <div class="account"><span class="name">alice_wong</span><span class="role">Admin</span></div>
                <div class="account"><span class="name">priya_singh</span><span class="role">Staff</span></div>
            </div>
            <a href="../book-marketplace-frontend/login.html" class="btn">Go to Login →</a>
            <div class="warning">⚠️ Delete setup.php from your server after setup is complete.</div>
        </div>
        <?php else: ?>
        <div class="result failure">
            <h2>❌ Setup Failed</h2>
            <p>Check the error above. Common fixes:</p>
            <p>• Make sure MySQL is running in XAMPP</p>
            <p>• Make sure your MySQL root password is empty (default XAMPP)</p>
            <p>• Check that PHP has the pdo_mysql extension enabled</p>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>