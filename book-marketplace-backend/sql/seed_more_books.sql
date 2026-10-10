-- ============================================================
--  Extra catalogue stock: 20 more titles, all listed and buyable.
--
--  Three independent statements, run in order. No temporary table and
--  no session state, because a hosted SQL console may run each
--  statement on its own connection.
--
--  Safe to run more than once. Every insert is guarded, so a second
--  run adds nothing and changes nothing.
--
--  Sellers are liam_brown (6) and noah_martin (8) — both Active
--  Customers — so emma_clarke (5) can buy any of them. Nobody can
--  buy their own listing, which is why none are hers.
-- ============================================================

USE book_marketplace;

-- 1. The catalogue entries (skipped when that ISBN is already catalogued)
INSERT INTO `BOOKS_CATALOG` (`category_id`, `managed_by_admin_id`, `title`, `author`, `isbn`)
SELECT s.`category_id`, 1, s.`title`, s.`author`, s.`isbn`
FROM (
              SELECT  3 AS `category_id`, 'The Hobbit'                  AS `title`, 'J. R. R. Tolkien'  AS `author`, '9780547928227' AS `isbn`
    UNION ALL SELECT  1, 'Nineteen Eighty-Four',        'George Orwell',     '9780451524935'
    UNION ALL SELECT  5, 'Pride and Prejudice',         'Jane Austen',       '9780141439518'
    UNION ALL SELECT  2, 'Sapiens',                     'Yuval Noah Harari', '9780062316097'
    UNION ALL SELECT  3, 'The Hunger Games',            'Suzanne Collins',   '9780439023481'
    UNION ALL SELECT  4, 'Gone Girl',                   'Gillian Flynn',     '9780307588371'
    UNION ALL SELECT  1, 'The Alchemist',               'Paulo Coelho',      '9780062315007'
    UNION ALL SELECT  6, 'Educated',                    'Tara Westover',     '9780399590504'
    UNION ALL SELECT  1, 'The Midnight Library',        'Matt Haig',         '9780525559474'
    UNION ALL SELECT  9, 'Atomic Habits',               'James Clear',       '9780735211292'
    UNION ALL SELECT  3, 'The Martian',                 'Andy Weir',         '9780553418026'
    UNION ALL SELECT  4, 'The Girl on the Train',       'Paula Hawkins',     '9781594634024'
    UNION ALL SELECT  1, 'Where the Crawdads Sing',     'Delia Owens',       '9780735219090'
    UNION ALL SELECT  6, 'Becoming',                    'Michelle Obama',    '9781524763138'
    UNION ALL SELECT  3, 'Dune',                        'Frank Herbert',     '9780441013593'
    UNION ALL SELECT  8, 'Clean Code',                  'Robert C. Martin',  '9780132350884'
    UNION ALL SELECT  7, 'The Very Hungry Caterpillar', 'Eric Carle',        '9780399226908'
    UNION ALL SELECT 10, 'Watchmen',                    'Alan Moore',        '9780930289232'
    UNION ALL SELECT  2, 'Thinking, Fast and Slow',     'Daniel Kahneman',   '9780374533557'
    UNION ALL SELECT  5, 'Normal People',               'Sally Rooney',      '9781984822178'
) s
WHERE NOT EXISTS (
    SELECT 1 FROM `BOOKS_CATALOG` b WHERE b.`isbn` = s.`isbn`
);

-- 2. Link each new book to its category
INSERT INTO `BOOK_CATEGORY_MAP` (`book_id`, `category_id`)
SELECT b.`book_id`, b.`category_id`
FROM `BOOKS_CATALOG` b
WHERE b.`isbn` IN (
    '9780547928227','9780451524935','9780141439518','9780062316097','9780439023481',
    '9780307588371','9780062315007','9780399590504','9780525559474','9780735211292',
    '9780553418026','9781594634024','9780735219090','9781524763138','9780441013593',
    '9780132350884','9780399226908','9780930289232','9780374533557','9781984822178'
)
AND NOT EXISTS (
    SELECT 1 FROM `BOOK_CATEGORY_MAP` m
    WHERE m.`book_id` = b.`book_id` AND m.`category_id` = b.`category_id`
);

-- 3. Put each book on a shelf as an available listing
INSERT INTO `USER_BOOKS`
    (`book_id`, `seller_id`, `listing_type`, `price`, `condition`, `status`)
SELECT b.`book_id`, s.`seller_id`, s.`listing_type`, s.`price`, s.`cond`, 'Available'
FROM (
              SELECT '9780547928227' AS `isbn`, 6 AS `seller_id`, 'For_sale'  AS `listing_type`, 450.00 AS `price`, 'Good'       AS `cond`
    UNION ALL SELECT '9780451524935', 8, 'For_sale',  320.00, 'Good'
    UNION ALL SELECT '9780141439518', 6, 'For_sale',  280.00, 'Acceptable'
    UNION ALL SELECT '9780062316097', 8, 'For_sale',  720.00, 'New'
    UNION ALL SELECT '9780439023481', 6, 'For_sale',  390.00, 'Good'
    UNION ALL SELECT '9780307588371', 8, 'For_sale',  350.00, 'Good'
    UNION ALL SELECT '9780062315007', 6, 'Both',      300.00, 'Good'
    UNION ALL SELECT '9780399590504', 8, 'For_sale',  540.00, 'New'
    UNION ALL SELECT '9780525559474', 6, 'For_sale',  420.00, 'Good'
    UNION ALL SELECT '9780735211292', 8, 'For_sale',  650.00, 'New'
    UNION ALL SELECT '9780553418026', 6, 'For_sale',  410.00, 'Good'
    UNION ALL SELECT '9781594634024', 8, 'For_sale',  310.00, 'Acceptable'
    UNION ALL SELECT '9780735219090', 6, 'For_sale',  480.00, 'Good'
    UNION ALL SELECT '9781524763138', 8, 'For_sale',  690.00, 'New'
    UNION ALL SELECT '9780441013593', 6, 'For_trade',   NULL, 'Good'
    UNION ALL SELECT '9780132350884', 8, 'For_sale',  980.00, 'Good'
    UNION ALL SELECT '9780399226908', 6, 'For_sale',  220.00, 'Good'
    UNION ALL SELECT '9780930289232', 8, 'For_sale',  760.00, 'Good'
    UNION ALL SELECT '9780374533557', 6, 'For_sale',  610.00, 'Acceptable'
    UNION ALL SELECT '9781984822178', 8, 'Both',      340.00, 'Good'
) s
JOIN `BOOKS_CATALOG` b ON b.`isbn` = s.`isbn`
WHERE NOT EXISTS (
    SELECT 1 FROM `USER_BOOKS` u
    WHERE u.`book_id` = b.`book_id` AND u.`seller_id` = s.`seller_id`
);
