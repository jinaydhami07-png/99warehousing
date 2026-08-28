import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { ok, handler } from '@/lib/apiResponse';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  await connectDB();
  await requireAdmin(req);
  const items = await User.find().sort({ createdAt: -1 }).limit(500);
  return ok({ items: items.map((u) => u.toPublic()) });
});
