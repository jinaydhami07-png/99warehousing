import { cookies } from 'next/headers';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { verifyRefresh, issueSession, clearRefreshCookie, REFRESH_COOKIE } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const POST = handler(async () => {
  const token = cookies().get(REFRESH_COOKIE)?.value;
  if (!token) throw ApiError.unauthorized('No active session.');

  let payload;
  try { payload = verifyRefresh(token); }
  catch { clearRefreshCookie(); throw ApiError.unauthorized('Session expired. Sign in again.'); }

  await connectDB();
  const user = await User.findById(payload.sub);
  if (!user || !user.isActive) throw ApiError.unauthorized('Account unavailable.');

  // A logout elsewhere bumps tokenVersion, retiring this token.
  if ((payload.v || 0) !== (user.tokenVersion || 0)) {
    clearRefreshCookie();
    throw ApiError.unauthorized('Session was ended. Sign in again.');
  }

  return ok(issueSession(user));
});
