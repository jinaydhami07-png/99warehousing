import { ok, handler } from '@/lib/apiResponse';
import { getUser, clearRefreshCookie } from '@/lib/auth';

export const POST = handler(async (req) => {
  const user = await getUser(req);
  // Invalidates every refresh token previously issued to this account.
  if (user) {
    user.tokenVersion = (user.tokenVersion || 0) + 1;
    await user.save();
  }
  clearRefreshCookie();
  return ok({ ok: true });
});
