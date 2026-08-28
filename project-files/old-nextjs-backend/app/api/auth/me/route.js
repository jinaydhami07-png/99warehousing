import { ok, handler } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  const user = await requireUser(req);
  return ok({ user: user.toPublic() });
});
