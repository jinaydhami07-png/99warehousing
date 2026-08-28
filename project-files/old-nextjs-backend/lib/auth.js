/**
 * Session handling.
 *
 * Access token  → returned to the page, held in memory/sessionStorage, 15 min.
 * Refresh token → httpOnly cookie, 7 days. JavaScript cannot read it, so an
 *                 XSS bug cannot steal a long-lived session.
 */
import crypto from 'crypto';
import jwt from 'jsonwebtoken';
import { cookies } from 'next/headers';
import { connectDB } from './db';
import User from './models/User';
import { ApiError } from './apiResponse';

export const REFRESH_COOKIE = 'bpsf_rt';

/**
 * Token signing keys.
 *
 * If JWT_ACCESS_SECRET / JWT_REFRESH_SECRET are set they are used as-is.
 * Otherwise they are derived from MONGODB_URI, so there is nothing to
 * generate by hand — pasting the connection string is enough to get running.
 *
 * The derivation is a SHA-256 of the connection string plus a per-purpose
 * label. That string already contains the database password, so it is both
 * high-entropy and secret; anyone who knows it already has the whole
 * database, so this is not a meaningful downgrade. It is also stable, so
 * sessions survive restarts.
 *
 * For production, setting the two variables explicitly is still preferable:
 * it lets you rotate signing keys without touching the database URI.
 */
function secretFor(purpose) {
  const explicit = purpose === 'access'
    ? process.env.JWT_ACCESS_SECRET
    : process.env.JWT_REFRESH_SECRET;

  if (explicit?.trim()) return explicit.trim();

  const uri = process.env.MONGODB_URI;
  if (!uri?.trim()) {
    throw new Error(
      'MONGODB_URI is not set. Open .env.local and paste your MongoDB connection ' +
        'string on line 5. Get one free at https://cloud.mongodb.com'
    );
  }
  return crypto.createHash('sha256').update(`bpsf:${purpose}:${uri.trim()}`).digest('hex');
}

const ACCESS_SECRET = () => secretFor('access');
const REFRESH_SECRET = () => secretFor('refresh');

export function signAccess(user) {
  return jwt.sign(
    { sub: user._id.toString(), role: user.role, email: user.email },
    ACCESS_SECRET(),
    { expiresIn: process.env.JWT_ACCESS_EXPIRES || '15m' }
  );
}

export function signRefresh(user) {
  return jwt.sign(
    { sub: user._id.toString(), v: user.tokenVersion || 0 },
    REFRESH_SECRET(),
    { expiresIn: process.env.JWT_REFRESH_EXPIRES || '7d' }
  );
}

export const verifyAccess = (t) => jwt.verify(t, ACCESS_SECRET());
export const verifyRefresh = (t) => jwt.verify(t, REFRESH_SECRET());

export function setRefreshCookie(user) {
  cookies().set(REFRESH_COOKIE, signRefresh(user), {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production', // requires HTTPS in prod
    sameSite: 'lax',
    path: '/api/auth',
    maxAge: 7 * 24 * 60 * 60,
  });
}

export function clearRefreshCookie() {
  cookies().set(REFRESH_COOKIE, '', { httpOnly: true, path: '/api/auth', maxAge: 0 });
}

/** Standard sign-in response: cookie set, access token + user returned. */
export function issueSession(user) {
  setRefreshCookie(user);
  return { accessToken: signAccess(user), user: user.toPublic() };
}

function bearer(req) {
  const h = req.headers.get('authorization') || '';
  return h.startsWith('Bearer ') ? h.slice(7).trim() : null;
}

/** Resolves the signed-in user, or null. Never throws for "not signed in". */
export async function getUser(req) {
  const token = bearer(req);
  if (!token) return null;

  let payload;
  try { payload = verifyAccess(token); }
  catch { return null; }

  await connectDB();
  const user = await User.findById(payload.sub);
  return user && user.isActive ? user : null;
}

/** Requires any signed-in user. */
export async function requireUser(req) {
  const user = await getUser(req);
  if (!user) throw ApiError.unauthorized();
  return user;
}

/**
 * Requires one of the given roles. This is the real authorisation gate —
 * hiding a button in the UI is convenience, this is enforcement.
 */
export async function requireRole(req, ...roles) {
  const user = await requireUser(req);
  if (!roles.includes(user.role)) {
    throw ApiError.forbidden(`This action requires the ${roles.join(' or ')} role.`);
  }
  return user;
}

export const requireAdmin = (req) => requireRole(req, 'admin');

/** Admins may act on anything; everyone else only on their own documents. */
export function assertOwnership(user, doc, field = 'owner') {
  if (user.role === 'admin') return;
  const ownerId = doc[field]?._id?.toString() || doc[field]?.toString();
  if (ownerId !== user._id.toString()) {
    throw ApiError.forbidden('You can only modify your own listings.');
  }
}
