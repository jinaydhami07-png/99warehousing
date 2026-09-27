<?php
/**
 * Router for PHP's built-in development server ONLY.
 *
 *     php -S localhost:8080 -t public dev-router.php
 *
 * The built-in server has no mod_rewrite, so public/.htaccess does nothing
 * under it. This reproduces the one rule that matters — /api/v1/* goes to
 * api.php — and serves everything else as a static file, which is what
 * Apache does in production.
 *
 * Not used on the real host. Apache reads public/.htaccess there.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (strpos($path, '/api/v1') === 0) {
    require __DIR__ . '/public/api.php';
    return true;
}

$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it
}

if ($path === '/' || is_dir($file)) {
    $index = rtrim($file, '/') . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
        return true;
    }
}

return false;
