/**
 * Google OAuth 2.0 (authorisation-code flow).
 *
 * Credentials come from .env.local section 4:
 *   GOOGLE_CLIENT_ID
 *   GOOGLE_CLIENT_SECRET
 *
 * The redirect URI registered in Google Cloud Console must match
 * `redirectUri()` below exactly, or Google returns redirect_uri_mismatch.
 */
import { OAuth2Client } from 'google-auth-library';

const has = (v) => typeof v === 'string' && v.trim().length > 0;

export const googleConfigured = () =>
  has(process.env.GOOGLE_CLIENT_ID) && has(process.env.GOOGLE_CLIENT_SECRET);

/** Must be byte-identical to the "Authorised redirect URI" in Google Console. */
export function redirectUri() {
  const base = process.env.NEXT_PUBLIC_APP_URL || 'http://localhost:3000';
  return `${base.replace(/\/$/, '')}/api/auth/google/callback`;
}

function client() {
  return new OAuth2Client(
    process.env.GOOGLE_CLIENT_ID,
    process.env.GOOGLE_CLIENT_SECRET,
    redirectUri()
  );
}

export function googleAuthUrl() {
  return client().generateAuthUrl({
    access_type: 'offline',
    prompt: 'select_account',
    scope: ['openid', 'email', 'profile'],
  });
}

/**
 * Trades the one-time code for tokens and verifies the returned ID token.
 * Verification matters: it proves the token was minted by Google for *this*
 * client, rather than trusting whatever the redirect handed us.
 */
export async function exchangeCode(code) {
  const oauth = client();
  const { tokens } = await oauth.getToken(code);
  if (!tokens.id_token) throw new Error('Google did not return an id_token');

  const ticket = await oauth.verifyIdToken({
    idToken: tokens.id_token,
    audience: process.env.GOOGLE_CLIENT_ID,
  });

  const p = ticket.getPayload();
  if (!p?.email_verified) throw new Error('Google account email is not verified');

  return { sub: p.sub, email: p.email, name: p.name, picture: p.picture };
}
