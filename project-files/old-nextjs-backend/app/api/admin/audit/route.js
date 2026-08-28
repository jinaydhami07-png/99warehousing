import { connectDB } from '@/lib/db';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler } from '@/lib/apiResponse';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  await connectDB();
  await requireAdmin(req);
  const items = await AuditLog.find().sort({ createdAt: -1 }).limit(200);
  return ok({ items });
});
