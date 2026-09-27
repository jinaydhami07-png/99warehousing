<?php
/**
 * API front controller.
 *
 * Every /api/v1/* request lands here, rewritten by public/.htaccess. This
 * is the PHP equivalent of server/src/app.js — the same middleware, in the
 * same order, for the same reasons.
 *
 * ── MIDDLEWARE ORDER IS LOAD-BEARING ─────────────────────────────────
 *   1. bootstrap        autoloader and error handling, before anything can fail
 *   2. security headers before anything can produce a response
 *   3. CORS             before routing, so preflights are answered
 *   4. request parsing  body and query, sanitised
 *   5. rate limiting    after the real client IP is known
 *   6. routes
 *   7. error handler    catches everything above
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

/* ── Finding the application ──────────────────────────────────────────
   This file is the only part of the app inside the web root, and where the
   rest lives depends on the layout:

     development / single-folder     ../app/bootstrap.php
     cPanel, app outside the docroot ../<app-folder>/app/bootstrap.php

   In the second layout public_html is a SIBLING of the application, not its
   child, so `dirname(__DIR__)` is the account's home directory. Rather than
   hardcode a folder name that changes per install, look in the obvious
   places and use the first that exists.

   APP_DIR in the environment overrides the search, for a layout that is
   neither of these. */
$candidates = [];

if (($fromEnv = getenv('APP_DIR')) !== false && $fromEnv !== '') {
    $candidates[] = rtrim($fromEnv, '/');
}

$candidates[] = dirname(__DIR__);                          // app is the parent
$candidates[] = dirname(__DIR__) . '/99warehousing-app';   // sibling, as shipped

/* Any sibling directory holding an app/bootstrap.php — so renaming the
   application folder does not break the site. */
foreach ((array) glob(dirname(__DIR__) . '/*/app/bootstrap.php') as $found) {
    $candidates[] = dirname(dirname($found));
}

$bootstrap = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $bootstrap = $candidate . '/app/bootstrap.php';
        break;
    }
}

if ($bootstrap === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Application files not found. See DEPLOY-TO-GODADDY.md — the app folder must sit beside public_html, or set APP_DIR.',
    ]);
    exit;
}

/* This directory IS the web root — whatever the layout. Uploads are written
   here and served from here, so the app is told rather than left to guess.
   Env reads this when PUBLIC_DIR is not set explicitly. */
define('PUBLIC_ROOT', __DIR__);

require_once $bootstrap;

use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\RateLimit;
use App\Middleware\Security;

$request = null;

try {
    /* Errors are reported through the JSON envelope, never printed into the
       response body. A PHP notice rendered before the JSON makes the whole
       response unparseable, and api.js — which decides "is there a backend
       here?" from the content type — would fall back to demo data. */
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    /* Touching Env first means a broken config fails here, with a readable
       message, rather than halfway through a handler. */
    Env::all();

    /* Headers first, before anything that can fail. Parsing the body can
       itself throw — malformed JSON, a body over the limit — and an error
       response deserves the same nosniff and CSP as a successful one. */
    Security::headers();

    $request = Request::capture();

    Security::cors($request); // exits on a preflight

    RateLimit::global($request);
    RateLimit::sweep();

    $router = new Router();
    require APP_ROOT . '/app/routes.php';

    $router->dispatch($request);
} catch (Throwable $e) {
    Response::error($e, $request);
}
