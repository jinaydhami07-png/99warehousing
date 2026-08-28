/**
 * Authentication service — token issuing and session lifecycle.
 *
 * Token strategy:
 *   • Access token  — 15 minutes, sent in the Authorization header, held in
 *                     memory by the client. Short-lived so a leaked token
 *                     has a small blast radius.
 *   • Refresh token — 7 days, delivered as an httpOnly cookie. JavaScript
 *                     cannot read it, so an XSS bug cannot exfiltrate a
 *                     long-lived credential.
 *
 * Refresh tokens carry a `tokenVersion` claim. Logging out increments the
 * stored version, which invalidates every refresh token already issued to
 * that user — "sign out everywhere" without maintaining a blacklist.
 */
'use strict';

const crypto = require('crypto');
const jwt = require('jsonwebtoken');
const env = require('../config/env');
const User = require('../models/user.model');
const ApiError = require('../utils/ApiError');
const userService = require('./user.service');

const REFRESH_COOKIE = 'bpsf_refresh';

const signAccessToken = (user) =>
  jwt.sign(
    { sub: user._id.toString(), role: user.role },
    env.jwt.accessSecret,
    { expiresIn: env.jwt.accessExpires, issuer: 'bpsf-api', audience: 'bpsf-client' }
  );

const signRefreshToken = (user) =>
  jwt.sign(
    { sub: user._id.toString(), ver: user.tokenVersion || 0 },
    env.jwt.refreshSecret,
    { expiresIn: env.jwt.refreshExpires, issuer: 'bpsf-api', audience: 'bpsf-client' }
  );

/** Cookie options kept in one place so they cannot drift between routes. */
const refreshCookieOptions = () => ({
  httpOnly: true,                 // unreadable from JavaScript
  secure: env.isProd,             // HTTPS only in production
  sameSite: env.isProd ? 'strict' : 'lax', // CSRF mitigation
  path: '/api/v1/auth',           // never sent to unrelated endpoints
  maxAge: 7 * 24 * 60 * 60 * 1000,
});

function setRefreshCookie(res, user) {
  res.cookie(REFRESH_COOKIE, signRefreshToken(user), refreshCookieOptions());
}

function clearRefreshCookie(res) {
  res.clearCookie(REFRESH_COOKIE, { ...refreshCookieOptions(), maxAge: undefined });
}

/** Registers a user and starts a session. */
async function register(res, payload) {
  const user = await userService.createUser(payload);
  setRefreshCookie(res, user);
  return { accessToken: signAccessToken(user), user };
}

/** Signs in an existing user. */
async function login(res, { email, password }) {
  const user = await userService.verifyCredentials(email, password);
  setRefreshCookie(res, user);
  return { accessToken: signAccessToken(user), user };
}

/**
 * Admin passkey sign-in, used by the dashboard's "Staff access" panel.
 *
 * The passkey is compared here on the server and never sent to the browser,
 * so it cannot be read out of page source the way a client-side check could.
 * Comparison is constant-time to avoid leaking the prefix through response
 * timing.
 */
async function adminLogin(res, passkey) {
  const expected = env.adminPasskey;

  if (!expected) {
    throw new ApiError(503, 'Admin access is not configured on this server');
  }

  const a = Buffer.from(String(passkey));
  const b = Buffer.from(String(expected));
  const matches = a.length === b.length && crypto.timingSafeEqual(a, b);

  if (!matches) throw ApiError.unauthorized('Incorrect admin passkey');

  // Bootstrap a single admin identity on first use so audit trails and
  // ownership checks have a real user to point at.
  let admin = await User.findOne({ role: 'admin' });
  if (!admin) {
    admin = await User.create({
      name: 'BPSF Admin',
      email: 'admin@buypersqft.local',
      role: 'admin',
      isEmailVerified: true,
      authProvider: 'local',
      // Never used to sign in — the passkey is the only route to this account.
      password: crypto.randomBytes(24).toString('hex'),
    });
  }

  setRefreshCookie(res, admin);
  return { accessToken: signAccessToken(admin), user: admin };
}

/**
 * Exchanges a valid refresh cookie for a new access token.
 * Rejects tokens whose version is stale (i.e. issued before a logout).
 */
async function refresh(req, res) {
  const token = req.cookies?.[REFRESH_COOKIE];
  if (!token) throw ApiError.unauthorized('No active session');

  let payload;
  try {
    payload = jwt.verify(token, env.jwt.refreshSecret, {
      algorithms: ['HS256'],
      issuer: 'bpsf-api',
      audience: 'bpsf-client',
    });
  } catch {
    clearRefreshCookie(res);
    throw ApiError.unauthorized('Session expired. Please sign in again.');
  }

  const user = await User.findById(payload.sub).select('+tokenVersion');
  if (!user || !user.isActive) {
    clearRefreshCookie(res);
    throw ApiError.unauthorized('Account unavailable');
  }

  if ((payload.ver ?? 0) !== (user.tokenVersion ?? 0)) {
    clearRefreshCookie(res);
    throw ApiError.unauthorized('Session was ended. Please sign in again.');
  }

  // Rotate the refresh cookie on every use, so a stolen one has a short life.
  setRefreshCookie(res, user);
  return { accessToken: signAccessToken(user), user };
}

/** Ends the session everywhere by bumping the token version. */
async function logout(req, res) {
  if (req.user) await userService.revokeSessions(req.user._id);
  clearRefreshCookie(res);
}

module.exports = {
  register,
  login,
  adminLogin,
  refresh,
  logout,
  signAccessToken,
  REFRESH_COOKIE,
};
