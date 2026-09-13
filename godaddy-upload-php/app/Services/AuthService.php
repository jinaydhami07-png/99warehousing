<?php
/**
 * Sessions: registration, sign-in, refresh, sign-out, and Google OAuth.
 *
 * ── The two-token split ───────────────────────────────────────────────
 * A short-lived ACCESS token goes to JavaScript, which puts it in an
 * Authorization header. A long-lived REFRESH token goes into an httpOnly
 * cookie the browser sends automatically and JavaScript cannot read.
 *
 * The point is the blast radius. XSS that steals the access token gets
 * fifteen minutes; it cannot reach the refresh cookie at all. And because
 * the refresh cookie is scoped to /api/v1/auth, it is never sent to any
 * other endpoint, so it cannot leak through a logging middleware or a
 * mis-set CORS header somewhere else in the API.
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Config\Logger;
use App\Http\ApiError;
use App\Http\Request;
use App\Models\User;
use App\Support\Jwt;

final class AuthService
{
    private const REFRESH_COOKIE = 'bpsf_refresh';
    private const OAUTH_STATE_COOKIE = 'bpsf_oauth_state';
    private const OAUTH_CALLBACK_COOKIE = 'bpsf_oauth_cb';

    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /* ── Tokens ── */

    public static function signAccessToken(array $user): string
    {
        return Jwt::sign(
            ['sub' => $user['id'], 'role' => $user['role']],
            (string) Env::get('jwt.accessSecret'),
            (int) Env::get('jwt.accessTtl'),
            (string) Env::get('jwt.issuer'),
            (string) Env::get('jwt.audience')
        );
    }

    private static function signRefreshToken(array $user): string
    {
        return Jwt::sign(
            ['sub' => $user['id'], 'ver' => (int) ($user['token_version'] ?? 0)],
            (string) Env::get('jwt.refreshSecret'),
            (int) Env::get('jwt.refreshTtl'),
            (string) Env::get('jwt.issuer'),
            (string) Env::get('jwt.audience')
        );
    }

    private static function setRefreshCookie(array $user): void
    {
        self::cookie(self::REFRESH_COOKIE, self::signRefreshToken($user), (int) Env::get('jwt.refreshTtl'));
    }

    private static function clearRefreshCookie(): void
    {
        self::cookie(self::REFRESH_COOKIE, '', -3600);
    }

    private static function cookie(string $name, string $value, int $ttl, string $path = '/api/v1/auth'): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
            'path' => $path,
            'httponly' => true,                       // unreadable from JavaScript
            'secure' => Env::isProd(),                // HTTPS only in production
            'samesite' => Env::isProd() ? 'Strict' : 'Lax', // CSRF mitigation
        ]);
    }

    /* ── Local accounts ── */

    /** @param array<string,mixed> $payload */
    public static function register(array $payload): array
    {
        $user = UserService::createUser($payload);
        self::setRefreshCookie($user);

        return ['accessToken' => self::signAccessToken($user), 'user' => User::toJson($user)];
    }

    public static function login(string $email, string $password): array
    {
        $user = UserService::verifyCredentials($email, $password);
        self::setRefreshCookie($user);

        return ['accessToken' => self::signAccessToken($user), 'user' => User::toJson($user)];
    }

    /**
     * The staff passkey — one shared secret, exchanged for an admin session.
     *
     * Compared in constant time: a byte-by-byte comparison leaks, through
     * how long it takes to fail, how much of a guess was right. This is the
     * single most brute-forceable entry point in the system, which is also
     * why the route sits behind the auth rate limiter.
     */
    public static function adminLogin(string $passkey): array
    {
        $expected = Env::get('adminPasskey');
        if (!is_string($expected) || $expected === '') {
            throw new ApiError(503, 'Admin access is not configured on this server');
        }
        if (!hash_equals($expected, $passkey)) {
            throw ApiError::unauthorized('Incorrect admin passkey');
        }

        $admin = User::firstAdmin();
        if ($admin === null) {
            /* First use on a fresh install. The account is created with a
               random password nobody holds — the passkey is the credential,
               and a known or empty password here would be a second, weaker
               door into the same room. */
            $admin = User::create([
                'name' => '99Warehousing Admin',
                'email' => 'admin@99warehousing.local',
                'password' => bin2hex(random_bytes(24)),
                'role' => 'admin',
                'isEmailVerified' => true,
                'authProvider' => 'local',
            ]);
            Logger::info('Admin account created on first passkey use', ['userId' => $admin['id']]);
        }

        self::setRefreshCookie($admin);
        return ['accessToken' => self::signAccessToken($admin), 'user' => User::toJson($admin)];
    }

    /**
     * Exchange the refresh cookie for a new access token.
     *
     * The stored token_version is checked against the one baked into the
     * cookie, which is what makes "log out everywhere" work without keeping
     * a blacklist: bumping the counter invalidates every token ever issued.
     */
    public static function refresh(Request $request): array
    {
        $token = $request->cookie(self::REFRESH_COOKIE);
        if ($token === null) {
            throw ApiError::unauthorized('No active session');
        }

        try {
            $payload = Jwt::verify(
                $token,
                (string) Env::get('jwt.refreshSecret'),
                (string) Env::get('jwt.issuer'),
                (string) Env::get('jwt.audience')
            );
        } catch (\Throwable $e) {
            self::clearRefreshCookie();
            throw ApiError::unauthorized('Session expired. Please sign in again.');
        }

        $user = User::findById((string) ($payload['sub'] ?? ''));
        if ($user === null || !$user['is_active']) {
            self::clearRefreshCookie();
            throw ApiError::unauthorized('Account unavailable');
        }
        if ((int) ($payload['ver'] ?? 0) !== (int) $user['token_version']) {
            self::clearRefreshCookie();
            throw ApiError::unauthorized('Session was ended. Please sign in again.');
        }

        self::setRefreshCookie($user);
        return ['accessToken' => self::signAccessToken($user), 'user' => User::toJson($user)];
    }

    public static function logout(Request $request): void
    {
        if ($request->user !== null) {
            User::revokeSessions((string) $request->user['id']);
        }
        self::clearRefreshCookie();
    }

    /* ── Google OAuth ─────────────────────────────────────────────
       The authorization-code flow, spoken directly to Google rather than
       through a library: it is two HTTPS calls, and keeping it here keeps
       the session logic in one file instead of split across a strategy
       object.
       ───────────────────────────────────────────────────────────── */

    /**
     * Which of the configured callback URLs matches the host this request
     * arrived on. Lets one deployment serve localhost and the live domain
     * without editing config between them.
     */
    private static function pickCallbackUrl(Request $request): string
    {
        $list = (array) Env::get('google.callbackUrls', []);
        $fallback = (string) Env::get('google.callbackUrl');
        if (count($list) < 2) {
            return $fallback;
        }
        $origin = strtolower($request->baseUrl());
        foreach ($list as $candidate) {
            $parts = parse_url((string) $candidate);
            if (!$parts || !isset($parts['scheme'], $parts['host'])) {
                continue;
            }
            $candidateOrigin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            if ($candidateOrigin === $origin) {
                return (string) $candidate;
            }
        }
        return $fallback;
    }

    public static function googleAuthUrl(Request $request): string
    {
        if (!Env::get('google.enabled')) {
            throw new ApiError(503, 'Google sign-in is not configured on this server');
        }

        $redirectUri = self::pickCallbackUrl($request);

        /* Remembered so the token exchange can present the identical URI.
           Google compares the two character for character and refuses the
           exchange with redirect_uri_mismatch if they differ. */
        self::cookie(self::OAUTH_CALLBACK_COOKIE, $redirectUri, 600, '/');

        /* CSRF for the OAuth round trip: an attacker who can make the
           browser hit the callback with their own code would otherwise log
           the victim into the attacker's account. */
        $state = bin2hex(random_bytes(24));
        self::cookie(self::OAUTH_STATE_COOKIE, $state, 600, '/');

        return self::GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id' => Env::get('google.clientId'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /** @return array{accessToken:string,user:array<string,mixed>} */
    public static function googleCallback(Request $request, string $code, ?string $state): array
    {
        if (!Env::get('google.enabled')) {
            throw new ApiError(503, 'Google sign-in is not configured on this server');
        }

        $expected = $request->cookie(self::OAUTH_STATE_COOKIE);
        self::cookie(self::OAUTH_STATE_COOKIE, '', -1, '/');
        if (!is_string($expected) || $state === null || !hash_equals($expected, $state)) {
            throw ApiError::unauthorized('Sign-in request expired or was tampered with. Please try again.');
        }

        /* Read back from the cookie rather than recomputed, so the two
           cannot drift — and validated against the configured list, so a
           tampered cookie cannot introduce an arbitrary redirect. */
        $cookieCb = $request->cookie(self::OAUTH_CALLBACK_COOKIE);
        $redirectUri = in_array($cookieCb, (array) Env::get('google.callbackUrls', []), true)
            ? (string) $cookieCb
            : self::pickCallbackUrl($request);
        self::cookie(self::OAUTH_CALLBACK_COOKIE, '', -1, '/');

        $tokens = self::exchangeCode($code, $redirectUri);
        $profile = self::decodeIdToken((string) ($tokens['id_token'] ?? ''));

        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        if ($email === '') {
            throw ApiError::unauthorized('Google did not return an email address');
        }
        if (($profile['email_verified'] ?? true) === false) {
            throw ApiError::unauthorized('Your Google email address is not verified');
        }

        /* Look up by Google id first, then fall back to email, so someone
           who registered with a password can later sign in with Google and
           land in the same account rather than a duplicate. */
        $user = User::findByGoogleId((string) $profile['sub']);
        if ($user === null) {
            $user = User::findByEmail($email);
            if ($user !== null) {
                $user = User::update($user['id'], array_filter([
                    'google_id' => (string) $profile['sub'],
                    'is_email_verified' => 1,
                    'avatar' => $profile['picture'] ?? null,
                ], static fn($v) => $v !== null));
            }
        }

        if ($user === null) {
            $user = User::create([
                'name' => $profile['name'] ?? explode('@', $email)[0],
                'email' => $email,
                /* No password. A Google account has none to set, and
                   generating a throwaway would leave a credential nobody
                   knows that could still be brute-forced. */
                'password' => null,
                'googleId' => (string) $profile['sub'],
                'avatar' => $profile['picture'] ?? null,
                'authProvider' => 'google',
                'isEmailVerified' => true,
            ]);
            Logger::info('New account created via Google', ['userId' => $user['id']]);
        }

        if (!$user['is_active']) {
            throw ApiError::forbidden('This account has been disabled');
        }

        self::setRefreshCookie($user);
        return ['accessToken' => self::signAccessToken($user), 'user' => User::toJson($user)];
    }

    /** @return array<string,mixed> */
    private static function exchangeCode(string $code, string $redirectUri): array
    {
        $ch = curl_init(self::GOOGLE_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            /* Both on, explicitly. Turning either off is the standard "fix"
               for a certificate error and it silently converts this into an
               unauthenticated channel carrying our client secret. */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => Env::get('google.clientId'),
                'client_secret' => Env::get('google.clientSecret'),
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            Logger::warn('Google token exchange failed', [
                'status' => $status,
                'curl' => $curlError,
                'detail' => substr((string) $body, 0, 300),
            ]);
            throw ApiError::unauthorized('Google sign-in failed. Please try again.');
        }

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read the claims out of Google's ID token.
     *
     * Not verified cryptographically, and it does not need to be: the token
     * arrived over a verified TLS connection to Google's own endpoint, in
     * response to a request carrying our client secret. That is the same
     * reasoning the Node build used. It would need verifying if it ever
     * arrived from the browser instead.
     *
     * @return array<string,mixed>
     */
    private static function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            throw ApiError::unauthorized('Malformed response from Google');
        }
        $padded = str_pad(strtr($parts[1], '-_', '+/'), (int) (ceil(strlen($parts[1]) / 4) * 4), '=');
        $decoded = json_decode((string) base64_decode($padded, true), true);
        if (!is_array($decoded) || !isset($decoded['sub'])) {
            throw ApiError::unauthorized('Malformed response from Google');
        }
        return $decoded;
    }
}
