<?php
/**
 * Session endpoints.
 *
 * Controllers here are thin on purpose: validate, call a service, shape the
 * response. Anything that decides something belongs in the service, so the
 * public route and the admin route cannot drift apart.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Config\Logger;
use App\Http\Request;
use App\Http\Response;
use App\Middleware\RateLimit;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Validate;
use App\Validators\UserSchema;

final class AuthController
{
    public static function register(Request $request): void
    {
        $body = Validate::against(UserSchema::register(), $request->body());
        $result = AuthService::register($body);

        Response::created($result, 'Account created');
    }

    public static function login(Request $request): void
    {
        $body = Validate::against(UserSchema::login(), $request->body());
        $result = AuthService::login($body['email'], $body['password']);

        /* Only failed attempts should count against the sign-in limiter, or
           a user who signs in ten times in a day is locked out of their own
           account. The limiter counted this attempt before the handler ran;
           now that it succeeded, give it back. */
        RateLimit::forgive(RateLimit::authKey($request));

        Response::success($result, 'Signed in');
    }

    public static function adminLogin(Request $request): void
    {
        $body = Validate::against(UserSchema::adminLogin(), $request->body());
        $result = AuthService::adminLogin($body['passkey']);

        RateLimit::forgive(RateLimit::authKey($request));

        Response::success($result, 'Admin session started');
    }

    public static function me(Request $request): void
    {
        Response::success(['user' => User::toJson($request->user)], 'Session valid');
    }

    public static function refresh(Request $request): void
    {
        Response::success(AuthService::refresh($request), 'Session refreshed');
    }

    public static function logout(Request $request): void
    {
        AuthService::logout($request);
        Response::success(null, 'Signed out');
    }

    /* ── Google OAuth ──
       Full-page redirects: OAuth cannot run inside fetch(). Both endpoints
       redirect back to the login page with an explanatory flag rather than
       returning JSON, because the browser is navigating, not fetching. */

    public static function googleStart(Request $request): void
    {
        if (!Env::get('google.enabled')) {
            Response::redirect('/login.html?error=google_not_configured');
            return;
        }
        Response::redirect(AuthService::googleAuthUrl($request));
    }

    public static function googleCallback(Request $request): void
    {
        if (!Env::get('google.enabled')) {
            Response::redirect('/login.html?error=google_not_configured');
            return;
        }

        $query = $request->query();
        /* The user pressed Cancel, or Google refused. Not an error worth a
           stack trace — send them back to sign in normally. */
        if (!empty($query['error']) || empty($query['code'])) {
            Response::redirect('/login.html?error=google_cancelled');
            return;
        }

        try {
            $result = AuthService::googleCallback($request, (string) $query['code'], $query['state'] ?? null);
            /* The token goes in the fragment, not the query string: a
               fragment is never sent to the server, never written to an
               access log, and never leaks through a Referer header. api.js
               reads it on load and clears it. */
            Response::redirect('/login.html#token=' . rawurlencode($result['accessToken']));
        } catch (\Throwable $e) {
            Logger::warn('Google sign-in failed', ['err' => $e->getMessage()]);
            Response::redirect('/login.html?error=google_failed');
        }
    }
}
