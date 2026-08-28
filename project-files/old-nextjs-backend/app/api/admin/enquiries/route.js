import { connectDB } from '@/lib/db';
import Enquiry from '@/lib/models/Enquiry';
import { ok, handler } from '@/lib/apiResponse';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  await connectDB();
  await requireAdmin(req);
  const items = await Enquiry.find()
    .populate('property', 'name city rate')
    .sort({ createdAt: -1 })
    .limit(300);
  return ok({ items });
});
