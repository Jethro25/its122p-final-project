<?php
/**
 * Small helper for consistent JSON API responses.
 */
class Response
{
    /** Optional hook run just before a reply is sent (used by idempotency.php to remember replies). */
    public static $beforeSend = null;

    public static function json($data, int $statusCode = 200): void
    {
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_callable(self::$beforeSend)) {
            $hook = self::$beforeSend;
            self::$beforeSend = null;          // run once
            $hook($statusCode, $body);
        }
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo $body;
        exit;
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void
    {
        self::json(array_merge(['error' => $message], $extra), $statusCode);
    }
}
