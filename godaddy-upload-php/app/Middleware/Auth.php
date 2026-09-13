<?php
/**
 * Authentication and authorisation.
 *
 * Mirrors server/src/middleware/auth.middleware.js: a bearer access token in
 * the Authorization header, verified, then the user row loaded fresh from
 * the database on every request.
 *
 * Reloading rather than trusting the token's claims is deliberate. It is
 * what makes deactivating an account, changing a role, or a password change
 * take effect immediately instead of whenever the token happens to expire.
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Http\ApiError;
use App\Http\Request;
use App\Models\User;
use App\Support\Jwt;

final class Auth
{
    /** Required session. Throws 401/403 rather than degrading. */
    public static function required(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw ApiError::unauthorized('Sign in to continue');
        }

        $payload = Jwt::verify(
            $token,
            (string) Env::get('jwt.accessSecret'),
            (string) Env::get('jwt.issuer'),
            (string) Env::get('jwt.audience')
        );

        $user = User::findById((string) ($payload['sub'] ?? ''));
        if ($user === null) {
            throw ApiError::unauthorized('Account no longer exists');
        }
        if (!$user['is_active']) {
            throw ApiError::forbidden('This account has been deactivated');
        }

        /* "Log out everywhere" for a password change: any token issued
           before the change stops validating. The stored timestamp is
           backdated a second when it is written, because `iat` has
           second precision and a token minted in the same second would
           otherwise look like it predates the change. */
        if (User::passwordChangedAfter($user, (int) ($payload['iat'] ?? 0))) {
            throw ApiError::unauthorized('Password was changed. Please sign in again.');
        }

        $request->user = $user;
    }

    /**
     * Attach the user when a valid token happens to be present, and carry on
     * when it is not.
     *
     * For routes that are public but behave differently for a signed-in
     * viewer — the property detail page, where an owner can see their own
     * listing before it is approved.
     */
    public static function optional(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return;
        }
        try {
            $payload = Jwt::verify(
                $token,
                (string) Env::get('jwt.accessSecret'),
                (string) Env::get('jwt.issuer'),
                (string) Env::get('jwt.audience')
            );
            $user = User::findById((string) ($payload['sub'] ?? ''));
            if ($user !== null && $user['is_active']) {
                $request->user = $user;
            }
        } catch (\Throwable $e) {
            /* An expired or malformed token on an optional route means
               "treat this as a signed-out visitor", not "fail the request". */
        }
    }

    /**
     * Role gate. Returns a middleware so it can be composed per route:
     *     [ [Auth::class, 'required'], Auth::role('admin') ]
     */
    public static function role(string ...$roles): callable
    {
        return static function (Request $request) use ($roles): void {
            if ($request->user === null) {
                throw ApiError::unauthorized();
            }
            if (!in_array((string) $request->user['role'], $roles, true)) {
                throw ApiError::forbidden('This action requires the ' . implode(' or ', $roles) . ' role');
            }
        };
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }
}
