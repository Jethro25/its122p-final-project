<?php
/**
 * /sitemap.xml — served via vercel.json rewrite or api/index.php?__route=sitemap.php
 * Only public pages are listed; authenticated/management pages are intentionally excluded.
 */
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
       || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$base   = rtrim("{$scheme}://{$host}", '/');

$urls = [
    ['loc' => '/',             'priority' => '1.0', 'changefreq' => 'weekly'],
    ['loc' => '/browse.html',  'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => '/login.html',   'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => '/register.html','priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => '/support.html', 'priority' => '0.6', 'changefreq' => 'weekly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    $abs = $base . $url['loc'];
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($abs, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
    echo '    <changefreq>' . $url['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $url['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
