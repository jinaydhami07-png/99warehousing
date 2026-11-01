<?php
/**
 * Security headers and CORS — what helmet and the cors package did.
 *
 * ORDER MATTERS, and it is fixed in public/api.php:
 *   1. security headers   before anything can produce a response
 *   2. CORS               before routing, so preflights are answered
 *   3. rate limiting      after the real client IP is known
 *   4. routes
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Config\Logger;
use App\Http\ApiError;
use App\Http\Request;

final class Security
{
    /**
     * Content-Security-Policy and friends.
     *
     * The pages are served by Apache, not by PHP, so these headers only
     * reach API responses unless the vhost adds them site-wide —
     * public/.htaccess does exactly that for the HTML. They are set here as
     * well so an API response is covered even when .htaccess is not honoured
     * (some hosts disable AllowOverride).
     */
    public static function headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Resource-Policy: cross-origin');
        /* Do not advertise the runtime. Apache may still send its own
           Server header; expose_php=Off in php.ini removes the X-Powered-By. */
        header_remove('X-Powered-By');

        if (Env::isProd()) {
            /* Never send HSTS over plain http — a browser that receives it
                on localhost will refuse http there for a year. */
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        header('Content-Security-Policy: ' . self::csp());
    }

    private static function csp(): string
    {
        $imgHosts = [];
        $mediaBase = Env::get('media.publicBaseUrl');
        if (is_string($mediaBase) && $mediaBase !== '') {
            $origin = parse_url($mediaBase, PHP_URL_SCHEME) . '://' . parse_url($mediaBase, PHP_URL_HOST);
            $imgHosts[] = $origin;
        }

        $directives = [
            "default-src 'self'",
            /* 'unsafe-inline' is required by the pages as they stand: the
               HTML carries inline <script> blocks. scriptSrcAttr is granted
               separately because CSP treats onclick="…" as its own directive
               and the pages use roughly 310 of them — with it at 'none' the
               handlers are present in the DOM, compile to null, and every
               button on the site silently stops working with no console
               error. Granting it here adds nothing beyond what script-src
               already allows. The real fix is to move those handlers to
               addEventListener and drop 'unsafe-inline' from both. */
            "script-src 'self' 'unsafe-inline'",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            /* data: for the inlined blur placeholders on listing cards. */
            "img-src 'self' data: blob:" . ($imgHosts ? ' ' . implode(' ', $imgHosts) : ''),
            "connect-src 'self' " . implode(' ', Env::get('corsOrigins', [])),
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        if (Env::isProd()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    /**
     * CORS — an explicit allow-list, never a wildcard.
     *
     * `Access-Control-Allow-Credentials: true` is required for the refresh
     * cookie, and the spec forbids combining it with `*`. A wildcard here
     * would let any site on the internet make authenticated requests as a
     * signed-in user.
     *
     * Answers preflights itself and stops the request — an OPTIONS never
     * needs to reach a route.
     */
    public static function cors(Request $request): void
    {
        $origin = $request->origin();

        /* Same-origin requests send no Origin header. That is the normal
           case here: Apache serves the pages and the API from one host. */
        if ($origin !== null) {
            $allowed = in_array($origin, Env::get('corsOrigins', []), true);

            /* In development the pages are often opened through Live Server
               or a bundler on another port, and a CORS rejection there is
               indistinguishable in the browser from "the API is down" — the
               front-end falls back to demo data and quietly stops saving.
               Production keeps the strict list: isProd short-circuits before
               the pattern is even tested. */
            if (!$allowed && !Env::isProd()
                && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#', $origin) === 1) {
                $allowed = true;
            }

            if (!$allowed) {
                Logger::warn('Blocked by CORS', ['origin' => $origin]);
                throw ApiError::forbidden('Not allowed by CORS');
            }

            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            /* The response varies by Origin, so a shared cache must not
               hand one site's response to another. */
            header('Vary: Origin');
        }

        if ($request->method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 600');
            http_response_code(204);
            exit;
        }
    }
}
