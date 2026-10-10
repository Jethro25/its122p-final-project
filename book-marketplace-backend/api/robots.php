<?php
/**
 * /robots.txt — served via vercel.json rewrite or api/index.php?__route=robots.php
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
       || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$base   = "{$scheme}://{$host}";

echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /api/\n";
echo "Disallow: /book-marketplace-backend/\n";
echo "\nSitemap: {$base}/sitemap.xml\n";
