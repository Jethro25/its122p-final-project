<?php
/**
 * Soft-delete helper.
 *
 * Nothing in Librowse is permanently deleted from the database. "Deleting"
 * stamps a date column (deleted_at / cleared_at / revoked_at) instead, so the
 * row stays for logging and is simply hidden from the app.
 *
 * ensure_column() adds that column the first time it's needed, so existing
 * databases upgrade themselves. It runs at most once per table per request.
 */
function ensure_column(PDO $pdo, string $table, string $column, string $definition = 'DATETIME NULL DEFAULT NULL'): void
{
    static $checked = [];
    $key = $table . '.' . $column;
    if (isset($checked[$key])) return;
    $checked[$key] = true;

    try {
        $pdo->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException $e2) {
            // Another request may have added it at the same moment — continue
        }
    }
}
