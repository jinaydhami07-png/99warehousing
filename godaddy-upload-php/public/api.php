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

require_once dirname(__DIR__) . '/app/bootstrap.php';

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
