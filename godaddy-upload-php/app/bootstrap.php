<?php
/**
 * Application bootstrap.
 *
 * Loaded by public/api.php before anything else. Responsibilities:
 *   • register the class autoloader (no Composer — shared hosting often has
 *     no shell access to run it, and this app has no third-party packages)
 *   • turn PHP warnings into exceptions so a typo cannot half-execute a
 *     request and return a 200 with a broken body
 *   • make sure fatal errors still leave the client with our JSON envelope
 *     rather than an HTML error page the front-end cannot parse
 *
 * Nothing here talks to the database or reads request data. This file must
 * stay safe to include from a CLI script (bin/install.php, bin/seed.php).
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/* ── Autoloader ──
   PSR-4 style: App\Services\AuthService → app/Services/AuthService.php.
   A single str_replace, because the namespace mirrors the directory tree
   exactly and any mapping table would only be something to keep in sync. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/* ── Warnings become exceptions ──
   PHP's default is to print a notice and carry on with a null. In an API
   that means a malformed response with a 200 on it. Promoting them to
   ErrorException routes them through the same handler as everything else.

   DEPRECATIONS ARE THE EXCEPTION TO THAT. A deprecation is PHP telling us
   about a FUTURE version, not about anything wrong with this request — and
   this app is meant to run on whatever a shared host provides, from 7.4 to
   the current release. Throwing on them means every new PHP version can
   turn a working endpoint into a 500 over a function that still does
   exactly what it always did. They are logged and stepped over. */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect the error_reporting mask, so @-suppressed calls still work.
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        error_log(sprintf('[deprecated] %s in %s:%d', $message, $file, $line));
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* ── Last-resort fatal handler ──
   set_error_handler cannot catch a parse error or an exhausted memory
   limit. Without this the client gets Apache's HTML error page, and
   api.js — which decides "is there a backend here?" from the content type —
   would conclude the API does not exist and quietly fall back to demo data.
   A JSON 500 is the honest answer and keeps the front-end on the real API. */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log(sprintf('[fatal] %s in %s:%d', $err['message'], $err['file'], $err['line']));
    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong on our end',
    ], JSON_UNESCAPED_SLASHES);
});

require_once APP_ROOT . '/app/Support/helpers.php';
