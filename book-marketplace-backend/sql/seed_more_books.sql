-- ============================================================
--  Extra catalogue stock: 20 more titles, all listed and buyable.
--
--  Safe to run more than once. Every insert is guarded, so a second
--  run adds nothing and changes nothing.
--
--  Sellers are liam_brown (6) and noah_martin (8) — both Active
--  Customers — so emma_clarke (5) can buy any of them. Nobody can
--  buy their own listing, which is why none are hers.
-- ============================================================

CREATE TEMPORARY TABLE `_seed_books` (
    `isbn`         VARCHAR(20)  NOT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `author`       VARCHAR(255) NOT NULL,
    `category_id`  INT UNSIGNED NOT NULL,
    `seller_id`    INT UNSIGNED NOT NULL,
    `listing_type` ENUM('For_trade','For_sale','Both') NOT NULL,
    `price`        DECIMAL(10,2) NULL,
    `condition`    ENUM('New','Good','Acceptable') NOT NULL
);

INSERT INTO `_seed_books`
    (`isbn`, `title`, `author`, `category_id`, `seller_id`, `listing_type`, `price`, `condition`)
VALUES
    ('9780547928227', 'The Hobbit',                  'J. R. R. Tolkien',  3, 6, 'For_sale',  450.00, 'Good'),
    ('9780451524935', 'Nineteen Eighty-Four',        'George Orwell',     1, 8, 'For_sale',  320.00, 'Good'),
    ('9780141439518', 'Pride and Prejudice',         'Jane Austen',       5, 6, 'For_sale',  280.00, 'Acceptable'),
    ('9780062316097', 'Sapiens',                     'Yuval Noah Harari', 2, 8, 'For_sale',  720.00, 'New'),
    ('9780439023481', 'The Hunger Games',            'Suzanne Collins',   3, 6, 'For_sale',  390.00, 'Good'),
    ('9780307588371', 'Gone Girl',                   'Gillian Flynn',     4, 8, 'For_sale',  350.00, 'Good'),
    ('9780062315007', 'The Alchemist',               'Paulo Coelho',      1, 6, 'Both',      300.00, 'Good'),
    ('9780399590504', 'Educated',                    'Tara Westover',     6, 8, 'For_sale',  540.00, 'New'),
    ('9780525559474', 'The Midnight Library',        'Matt Haig',         1, 6, 'For_sale',  420.00, 'Good'),
    ('9780735211292', 'Atomic Habits',               'James Clear',       9, 8, 'For_sale',  650.00, 'New'),
    ('9780553418026', 'The Martian',                 'Andy Weir',         3, 6, 'For_sale',  410.00, 'Good'),
    ('9781594634024', 'The Girl on the Train',       'Paula Hawkins',     4, 8, 'For_sale',  310.00, 'Acceptable'),
    ('9780735219090', 'Where the Crawdads Sing',     'Delia Owens',       1, 6, 'For_sale',  480.00, 'Good'),
    ('9781524763138', 'Becoming',                    'Michelle Obama',    6, 8, 'For_sale',  690.00, 'New'),
    ('9780441013593', 'Dune',                        'Frank Herbert',     3, 6, 'For_trade',   NULL, 'Good'),
    ('9780132350884', 'Clean Code',                  'Robert C. Martin',  8, 8, 'For_sale',  980.00, 'Good'),
    ('9780399226908', 'The Very Hungry Caterpillar', 'Eric Carle',        7, 6, 'For_sale',  220.00, 'Good'),
    ('9780930289232', 'Watchmen',                    'Alan Moore',       10, 8, 'For_sale',  760.00, 'Good'),
    ('9780374533557', 'Thinking, Fast and Slow',     'Daniel Kahneman',   2, 6, 'For_sale',  610.00, 'Acceptable'),
    ('9781984822178', 'Normal People',               'Sally Rooney',      5, 8, 'Both',      340.00, 'Good');

-- The catalogue entry (skipped when that ISBN is already catalogued)
INSERT INTO `BOOKS_CATALOG` (`category_id`, `managed_by_admin_id`, `title`, `author`, `isbn`)
SELECT s.`category_id`, 1, s.`title`, s.`author`, s.`isbn`
FROM `_seed_books` s
WHERE NOT EXISTS (
    SELECT 1 FROM `BOOKS_CATALOG` b WHERE b.`isbn` = s.`isbn`
);

-- Its category link
INSERT INTO `BOOK_CATEGORY_MAP` (`book_id`, `category_id`)
SELECT b.`book_id`, s.`category_id`
FROM `_seed_books` s
JOIN `BOOKS_CATALOG` b ON b.`isbn` = s.`isbn`
WHERE NOT EXISTS (
    SELECT 1 FROM `BOOK_CATEGORY_MAP` m
    WHERE m.`book_id` = b.`book_id` AND m.`category_id` = s.`category_id`
);

-- The shelf listing itself
INSERT INTO `USER_BOOKS`
    (`book_id`, `seller_id`, `listing_type`, `price`, `condition`, `status`)
SELECT b.`book_id`, s.`seller_id`, s.`listing_type`, s.`price`, s.`condition`, 'Available'
FROM `_seed_books` s
JOIN `BOOKS_CATALOG` b ON b.`isbn` = s.`isbn`
WHERE NOT EXISTS (
    SELECT 1 FROM `USER_BOOKS` u
    WHERE u.`book_id` = b.`book_id` AND u.`seller_id` = s.`seller_id`
);

DROP TEMPORARY TABLE `_seed_books`;
